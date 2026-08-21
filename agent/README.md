# xpanel-terminal-agent

Bridges a browser WebSocket (opened from ikode's single "Terminal" workspace) to a
real SSH session on a site's own confined Unix user. It never runs as root,
never touches the Laravel database, and holds no Laravel authorization secret.
The service key alone cannot open a shell: `authorized_keys` forces every
connection through `xpanel-terminal-authorize`, which atomically consumes the
opaque token in Laravel and checks the exact target Unix identity.

See the plan/context in `app/Services/TerminalTokenIssuer.php` and
`scripts/xpanel-site-helper.sh::access_sync()` for the opaque token flow and the
sshd-side isolation this agent relies on instead of reimplementing.

The agent is not a command interpreter. The browser's xterm.js instance sends
keystrokes and terminal resize events; the agent only transports the resulting
PTY byte stream to and from the real login shell.

## Environment variables

| Variable | Required | Default | Purpose |
| --- | --- | --- | --- |
| `XPANEL_TERMINAL_LISTEN` | no | `127.0.0.1:7092` | Loopback-only; the panel's own Nginx vhost proxies `/terminal-ws` here. |
| `XPANEL_TERMINAL_SSH_KEY_PATH` | no | `/var/lib/xpanel-host/ssh/service_terminal` | Private half of the service keypair (0600, owned by the agent's own unprivileged system user). |
| `XPANEL_TERMINAL_SSH_HOST` | no | `127.0.0.1:22` | Where sshd actually lives. |

## Build

```bash
go build -C agent -o /usr/local/bin/xpanel-terminal-agent .
```

`install.sh` does this automatically when `XPANEL_TERMINAL_ENABLED=true`, downloading the official Go toolchain first if it isn't already present (mirrors how it bootstraps Node.js for Vite).

## Wire protocol

Once the WebSocket is open, both directions exchange single-line JSON frames:

```json
{"type":"data","data":"ls -la\n"}
{"type":"resize","cols":120,"rows":32}
{"type":"error","data":"No se pudo conectar por SSH: ..."}
```

## Why SSH instead of spawning a PTY directly

Spawning a PTY and dropping privileges would mean the agent itself needs
root-equivalent power (to `runuser` into arbitrary site users), making the
agent the highest-value target on the box. Shelling out to the box's own
sshd instead means the agent only ever holds one ordinary SSH keypair, and
every restriction (no port/X11/agent forwarding, pubkey-only, per-site
`Match User` block) is enforced by sshd itself — the exact same mechanism
already used for a human's own registered key.
