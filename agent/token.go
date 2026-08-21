package main

import "regexp"

type tokenPayload struct {
	SystemUser string
}

var (
	tokenRE      = regexp.MustCompile(`^[A-Za-z0-9]{64}$`)
	systemUserRE = regexp.MustCompile(`^(?:xps[a-z0-9]{9,29}|xpa[a-z0-9]{8,24}|xhi[a-f0-9]{12})$`)
)

func validTerminalRequest(token, systemUser string) bool {
	return tokenRE.MatchString(token) && systemUserRE.MatchString(systemUser)
}
