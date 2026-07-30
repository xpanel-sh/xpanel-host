#!/usr/bin/env bash
# ForceCommand target for the SSH Match block that grants a real shell
# (personal key or the browser's web terminal — sshd can't tell them apart,
# see access_sync() in xpanel-site-helper.sh). Runs as the connecting site
# user, never as root, and never with any extra privilege of its own: it
# only reads its own jail manifest (world-unreadable, group-readable by that
# same user) and re-execs into a bubblewrap sandbox that can only reach the
# document roots listed there — this site's own, plus its own subdomains',
# and nothing belonging to any other site on the box.
set -euo pipefail

site_user="$(id -un)"
manifest="/var/lib/xpanel-host/jails/$site_user/roots.list"

if [[ ! -r "$manifest" ]]; then
  echo "No hay carpetas asignadas todavia a esta identidad. Ejecuta la sincronizacion de sitios." >&2
  exit 1
fi

if ! command -v bwrap >/dev/null 2>&1; then
  echo "bubblewrap no esta instalado en este servidor." >&2
  exit 1
fi

args=(
  --ro-bind /usr /usr
  --ro-bind /bin /bin
  --ro-bind /lib /lib
  --proc /proc
  --dev /dev
  --tmpfs /tmp
  --unshare-pid
  --unshare-uts
  --hostname "$site_user"
  --die-with-parent
)

for optional in /lib64 /sbin /etc/alternatives /etc/ssl /usr/share/terminfo; do
  [[ -e "$optional" ]] && args+=(--ro-bind-try "$optional" "$optional")
done
for optional_file in /etc/resolv.conf /etc/nsswitch.conf /etc/passwd /etc/group /etc/hosts; do
  [[ -e "$optional_file" ]] && args+=(--ro-bind-try "$optional_file" "$optional_file")
done

primary=""
while IFS= read -r root; do
  [[ -z "$root" ]] && continue
  [[ -d "$root" ]] || continue
  args+=(--bind "$root" "$root")
  [[ -z "$primary" ]] && primary="$root"
done < "$manifest"

if [[ -z "$primary" ]]; then
  echo "Ninguna de las carpetas asignadas existe todavia en el disco." >&2
  exit 1
fi

args+=(--chdir "$primary")
args+=(--setenv HOME "$primary")
args+=(--setenv TERM "${TERM:-xterm-256color}")
args+=(--setenv PS1 '\u:\w\$ ')

exec bwrap "${args[@]}" /bin/bash --noprofile --norc -i
