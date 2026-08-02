package main

import "testing"

func TestValidTerminalRequest(t *testing.T) {
	if !validTerminalRequest("aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa", "xps1abcdef12") {
		t.Fatal("valid token and site identity were rejected")
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
