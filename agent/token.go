package main

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"
)

// tokenPayload mirrors the shape TerminalTokenIssuer::issue() encodes in
// app/Services/TerminalTokenIssuer.php. Both sides must stay in lockstep.
type tokenPayload struct {
	JTI        string `json:"jti"`
	SiteID     int64  `json:"site_id"`
	SystemUser string `json:"system_user"`
	Exp        int64  `json:"exp"`
}

var errInvalidToken = errors.New("invalid or expired token")

// verifySignature checks the HMAC and expiry locally, without any network
// call — a cheap first line of defense against garbage tokens before we
// bother burning a round trip to Laravel.
func verifySignature(token, key string) (tokenPayload, error) {
	parts := strings.SplitN(token, ".", 2)
	if len(parts) != 2 {
		return tokenPayload{}, errInvalidToken
	}
	body, signature := parts[0], parts[1]

	mac := hmac.New(sha256.New, []byte(key))
	mac.Write([]byte(body))
	expected := base64.RawURLEncoding.EncodeToString(mac.Sum(nil))
	if !hmac.Equal([]byte(expected), []byte(signature)) {
		return tokenPayload{}, errInvalidToken
	}

	raw, err := base64.RawURLEncoding.DecodeString(body)
	if err != nil {
		return tokenPayload{}, errInvalidToken
	}
	var payload tokenPayload
	if err := json.Unmarshal(raw, &payload); err != nil {
		return tokenPayload{}, errInvalidToken
	}
	if payload.JTI == "" || payload.SystemUser == "" || payload.Exp < time.Now().Unix() {
		return tokenPayload{}, errInvalidToken
	}

	return payload, nil
}

// consumeToken burns the token server-side (Cache::add is the only source of
// truth for single-use) before the agent is allowed to open the real SSH
// session. Even a token that passed verifySignature is worthless if this
// call fails or was already used.
func consumeToken(ctx context.Context, cfg *config, token string) (tokenPayload, error) {
	payload, err := verifySignature(token, cfg.SigningKey)
	if err != nil {
		return tokenPayload{}, err
	}

	form := url.Values{"token": {token}}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, cfg.ConsumeURL, strings.NewReader(form.Encode()))
	if err != nil {
		return tokenPayload{}, err
	}
	req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	req.Header.Set("Accept", "application/json")

	client := &http.Client{Timeout: 5 * time.Second}
	resp, err := client.Do(req)
	if err != nil {
		return tokenPayload{}, err
	}
	defer func() {
		_, _ = io.Copy(io.Discard, resp.Body)
		_ = resp.Body.Close()
	}()

	if resp.StatusCode != http.StatusOK {
		return tokenPayload{}, errInvalidToken
	}

	return payload, nil
}
