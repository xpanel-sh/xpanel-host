package main

import (
	"encoding/json"
	"io"
	"log"
	"net/http"
	"os"
	"sync"
	"time"

	"github.com/gorilla/websocket"
	"golang.org/x/crypto/ssh"
)

var upgrader = websocket.Upgrader{
	ReadBufferSize:  4096,
	WriteBufferSize: 4096,
}

type wsMessage struct {
	Type string `json:"type"`
	Data string `json:"data,omitempty"`
	Cols int    `json:"cols,omitempty"`
	Rows int    `json:"rows,omitempty"`
}

type socketWriter struct {
	conn *websocket.Conn
	mu   sync.Mutex
}

func (w *socketWriter) write(kind, message string) error {
	frame, err := json.Marshal(wsMessage{Type: kind, Data: message})
	if err != nil {
		return err
	}
	w.mu.Lock()
	defer w.mu.Unlock()

	return w.conn.WriteMessage(websocket.TextMessage, frame)
}

func handleTerminal(cfg *config) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		token := r.URL.Query().Get("token")
		systemUser := r.URL.Query().Get("user")
		if !validTerminalRequest(token, systemUser) {
			http.Error(w, "invalid terminal request", http.StatusForbidden)
			return
		}

		conn, err := upgrader.Upgrade(w, r, nil)
		if err != nil {
			log.Printf("terminal: websocket upgrade failed: %v", err)
			return
		}
		defer conn.Close()

		bridgeToSSH(conn, cfg, tokenPayload{SystemUser: systemUser}, token)
	}
}

func bridgeToSSH(conn *websocket.Conn, cfg *config, payload tokenPayload, token string) {
	writer := &socketWriter{conn: conn}
	signer, err := loadServiceKey(cfg.SSHKeyPath)
	if err != nil {
		_ = writer.write("error", "No se pudo cargar la llave de servicio del agente.")
		return
	}

	sshConfig := &ssh.ClientConfig{
		User: payload.SystemUser,
		Auth: []ssh.AuthMethod{ssh.PublicKeys(signer)},
		// Loopback-only by construction (cfg.SSHHost defaults to 127.0.0.1:22,
		// never a value the browser or an untrusted network can influence),
		// so pinning a host key here would only protect against a threat
		// that already implies the box itself is compromised.
		HostKeyCallback: ssh.InsecureIgnoreHostKey(),
		Timeout:         10 * time.Second,
	}

	client, err := ssh.Dial("tcp", cfg.SSHHost, sshConfig)
	if err != nil {
		_ = writer.write("error", "No se pudo conectar por SSH: "+err.Error())
		return
	}
	defer client.Close()

	session, err := client.NewSession()
	if err != nil {
		_ = writer.write("error", "No se pudo abrir la sesion SSH.")
		return
	}
	defer session.Close()

	stdin, err := session.StdinPipe()
	if err != nil {
		_ = writer.write("error", "No se pudo conectar la entrada de la terminal.")
		return
	}
	stdout, err := session.StdoutPipe()
	if err != nil {
		_ = writer.write("error", "No se pudo conectar la salida de la terminal.")
		return
	}
	stderr, err := session.StderrPipe()
	if err != nil {
		_ = writer.write("error", "No se pudo conectar la salida de error de la terminal.")
		return
	}

	modes := ssh.TerminalModes{
		ssh.ECHO:          1,
		ssh.TTY_OP_ISPEED: 14400,
		ssh.TTY_OP_OSPEED: 14400,
	}
	if err := session.RequestPty("xterm-256color", 24, 80, modes); err != nil {
		_ = writer.write("error", "No se pudo asignar una terminal (PTY).")
		return
	}
	// The service key is restricted in authorized_keys to a root-owned forced
	// command. That command consumes the opaque token and checks that Laravel
	// authorized exactly payload.SystemUser before it execs the login shell.
	if err := session.Start("xpanel-terminal " + token); err != nil {
		_ = writer.write("error", "No se pudo iniciar la shell.")
		return
	}

	done := make(chan struct{})
	var closeOnce sync.Once
	finish := func() { closeOnce.Do(func() { close(done) }) }

	go func() { pipeToSocket(writer, stdout); finish() }()
	go func() { pipeToSocket(writer, stderr); finish() }()
	go func() {
		<-done
		session.Close()
		conn.Close()
	}()

	for {
		_, raw, err := conn.ReadMessage()
		if err != nil {
			break
		}
		var msg wsMessage
		if err := json.Unmarshal(raw, &msg); err != nil {
			continue
		}
		switch msg.Type {
		case "data":
			_, _ = stdin.Write([]byte(msg.Data))
		case "resize":
			if msg.Cols > 0 && msg.Rows > 0 {
				_ = session.WindowChange(msg.Rows, msg.Cols)
			}
		}
	}

	finish()
	_ = session.Wait()
}

func pipeToSocket(writer *socketWriter, r io.Reader) {
	buf := make([]byte, 4096)
	for {
		n, err := r.Read(buf)
		if n > 0 {
			if writeErr := writer.write("data", string(buf[:n])); writeErr != nil {
				return
			}
		}
		if err != nil {
			return
		}
	}
}

func loadServiceKey(path string) (ssh.Signer, error) {
	keyBytes, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}

	return ssh.ParsePrivateKey(keyBytes)
}
