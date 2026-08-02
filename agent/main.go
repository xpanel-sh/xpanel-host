// xpanel-terminal-agent bridges a browser WebSocket to a real SSH session on
// a specific site's own confined Unix user. It never runs as root, never
// touches the Laravel database, and holds no Laravel authorization secret. It
// forwards an opaque token through SSH; a root-owned forced command consumes
// it in Laravel and verifies the Unix identity before starting the shell.
// (no forwarding, pubkey-only, per-site Match block) is enforced by sshd
// itself, exactly as it already is for a human's own registered SSH key —
// see scripts/xpanel-site-helper.sh::access_sync().
package main

import (
	"log"
	"net/http"
	"time"
)

func main() {
	cfg, err := loadConfig()
	if err != nil {
		log.Fatalf("xpanel-terminal-agent: %v", err)
	}

	mux := http.NewServeMux()
	mux.HandleFunc("/terminal-ws", handleTerminal(cfg))

	server := &http.Server{
		Addr:              cfg.ListenAddr,
		Handler:           mux,
		ReadHeaderTimeout: 10 * time.Second,
	}

	log.Printf("xpanel-terminal-agent listening on %s", cfg.ListenAddr)
	if err := server.ListenAndServe(); err != nil {
		log.Fatalf("xpanel-terminal-agent: %v", err)
	}
}
