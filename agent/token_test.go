package main

import "testing"

func TestValidTerminalRequest(t *testing.T) {
	identities := []string{"xps1abcdef12", "xpa0123456789", "xhi0123456789ab"}
	for _, identity := range identities {
		if !validTerminalRequest("aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa", identity) {
			t.Fatalf("valid token and identity were rejected: %s", identity)
		}
	}
}

func TestInvalidTerminalRequest(t *testing.T) {
	cases := [][2]string{
		{"short", "xps1abcdef12"},
		{"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa", "root"},
		{"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa!", "xps1abcdef12"},
	}
	for _, values := range cases {
		if validTerminalRequest(values[0], values[1]) {
			t.Fatalf("unsafe request was accepted: %#v", values)
		}
	}
}
