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

// The token is the real authentication boundary here, not the WS handshake's
// Origin header — the panel and the agent may not even share a scheme/port
// once proxied, and a same-origin check would just be security theater on
// top of a signed, single-use, 20-second-lived capability token.
var upgrader = websocket.Upgrader{
	ReadBufferSize:  4096,
	WriteBufferSize: 4096,
	CheckOrigin:     func(r *http.Request) bool { return true },
}

type wsMessage struct {
	Type string `json:"type"`
	Data string `json:"data,omitempty"`
	Cols int    `json:"cols,omitempty"`
	Rows int    `json:"rows,omitempty"`
}

func handleTerminal(cfg *config) http.HandlerFunc {
	return func(w http.ResponseWriter, r *http.Request) {
		token := r.URL.Query().Get("token")
		if token == "" {
			http.Error(w, "missing token", http.StatusUnauthorized)
			return
		}
		payload, err := consumeToken(r.Context(), cfg, token)
		if err != nil {
			http.Error(w, "invalid or expired token", http.StatusForbidden)
			return
		}

		conn, err := upgrader.Upgrade(w, r, nil)
		if err != nil {
			log.Printf("terminal: websocket upgrade failed: %v", err)
			return
		}
		defer conn.Close()

		bridgeToSSH(conn, cfg, payload)
	}
}

func bridgeToSSH(conn *websocket.Conn, cfg *config, payload tokenPayload) {
	signer, err := loadServiceKey(cfg.SSHKeyPath)
	if err != nil {
		writeFrame(conn, "error", "No se pudo cargar la llave de servicio del agente.")
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
		writeFrame(conn, "error", "No se pudo conectar por SSH: "+err.Error())
		return
	}
	defer client.Close()

	session, err := client.NewSession()
	if err != nil {
		writeFrame(conn, "error", "No se pudo abrir la sesion SSH.")
		return
	}
	defer session.Close()

	stdin, err := session.StdinPipe()
	if err != nil {
		writeFrame(conn, "error", "No se pudo conectar la entrada de la terminal.")
		return
	}
	stdout, err := session.StdoutPipe()
	if err != nil {
		writeFrame(conn, "error", "No se pudo conectar la salida de la terminal.")
		return
	}
	stderr, err := session.StderrPipe()
	if err != nil {
		writeFrame(conn, "error", "No se pudo conectar la salida de error de la terminal.")
		return
	}

	modes := ssh.TerminalModes{
		ssh.ECHO:          1,
		ssh.TTY_OP_ISPEED: 14400,
		ssh.TTY_OP_OSPEED: 14400,
	}
	if err := session.RequestPty("xterm-256color", 24, 80, modes); err != nil {
		writeFrame(conn, "error", "No se pudo asignar una terminal (PTY).")
		return
	}
	if err := session.Shell(); err != nil {
		writeFrame(conn, "error", "No se pudo iniciar la shell.")
		return
	}

	done := make(chan struct{})
	var closeOnce sync.Once
	finish := func() { closeOnce.Do(func() { close(done) }) }

	go func() { pipeToSocket(conn, stdout); finish() }()
	go func() { pipeToSocket(conn, stderr); finish() }()
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

func pipeToSocket(conn *websocket.Conn, r io.Reader) {
	buf := make([]byte, 4096)
	for {
		n, err := r.Read(buf)
		if n > 0 {
			frame, marshalErr := json.Marshal(wsMessage{Type: "data", Data: string(buf[:n])})
			if marshalErr == nil {
				if writeErr := conn.WriteMessage(websocket.TextMessage, frame); writeErr != nil {
					return
				}
			}
		}
		if err != nil {
			return
		}
	}
}

func writeFrame(conn *websocket.Conn, kind, message string) {
	frame, err := json.Marshal(wsMessage{Type: kind, Data: message})
	if err != nil {
		return
	}
	_ = conn.WriteMessage(websocket.TextMessage, frame)
}

func loadServiceKey(path string) (ssh.Signer, error) {
	keyBytes, err := os.ReadFile(path)
	if err != nil {
		return nil, err
	}

	return ssh.ParsePrivateKey(keyBytes)
}
