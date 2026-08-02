package main

import "os"

// config holds everything the agent needs, all sourced from the environment
// (systemd EnvironmentFile written by install.sh — see agent/README.md).
type config struct {
	ListenAddr string
	SSHKeyPath string
	SSHHost    string
}

func loadConfig() (*config, error) {
	cfg := &config{
		ListenAddr: getEnvDefault("XPANEL_TERMINAL_LISTEN", "127.0.0.1:7092"),
		SSHKeyPath: getEnvDefault("XPANEL_TERMINAL_SSH_KEY_PATH", "/var/lib/xpanel-host/ssh/service_terminal"),
		SSHHost:    getEnvDefault("XPANEL_TERMINAL_SSH_HOST", "127.0.0.1:22"),
	}

	return cfg, nil
}

func getEnvDefault(key, fallback string) string {
	if value := os.Getenv(key); value != "" {
		return value
	}

	return fallback
}
