package main

import "regexp"

type tokenPayload struct {
	SystemUser string
}

var (
	tokenRE      = regexp.MustCompile(`^[A-Za-z0-9]{64}$`)
	systemUserRE = regexp.MustCompile(`^xps[a-z0-9]{9,29}$`)
)

func validTerminalRequest(token, systemUser string) bool {
	return tokenRE.MatchString(token) && systemUserRE.MatchString(systemUser)
}
