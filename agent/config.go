package main

import (
	"fmt"
	"os"
)

// config holds everything the agent needs, all sourced from the environment
// (systemd EnvironmentFile written by install.sh — see agent/README.md).
type config struct {
	ListenAddr string
	SigningKey string
	ConsumeURL string
	SSHKeyPath string
	SSHHost    string
}

func loadConfig() (*config, error) {
	cfg := &config{
		ListenAddr: getEnvDefault("XPANEL_TERMINAL_LISTEN", "127.0.0.1:7092"),
		SigningKey: os.Getenv("XPANEL_TERMINAL_SIGNING_KEY"),
		ConsumeURL: os.Getenv("XPANEL_TERMINAL_CONSUME_URL"),
		SSHKeyPath: getEnvDefault("XPANEL_TERMINAL_SSH_KEY_PATH", "/var/lib/xpanel-host/ssh/service_terminal"),
		SSHHost:    getEnvDefault("XPANEL_TERMINAL_SSH_HOST", "127.0.0.1:22"),
	}
	if cfg.SigningKey == "" {
		return nil, fmt.Errorf("XPANEL_TERMINAL_SIGNING_KEY is required")
	}
	if cfg.ConsumeURL == "" {
		return nil, fmt.Errorf("XPANEL_TERMINAL_CONSUME_URL is required")
	}

	return cfg, nil
}

func getEnvDefault(key, fallback string) string {
	if value := os.Getenv(key); value != "" {
		return value
	}

	return fallback
}
