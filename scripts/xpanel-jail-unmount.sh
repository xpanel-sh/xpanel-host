#!/usr/bin/env bash
# Recursively tears down every bind mount under a per-site terminal jail.
# `umount -R` requires the given path itself to already be a mountpoint,
# which a jail directory never is here (only paths *inside* it are mounted,
# e.g. $jail/bin, $jail/etc/php) -- so it fails outright with "not mounted"
# instead of recursing. Enumerating the real mount tree with findmnt and
# unmounting deepest-first is what actually works.
set -uo pipefail

jail="${1:?usage: xpanel-jail-unmount.sh <jail-path>}"

findmnt -R -n -o TARGET --target "$jail" 2>/dev/null | tac | while IFS= read -r mountpoint; do
  umount "$mountpoint" 2>/dev/null || umount -l "$mountpoint" 2>/dev/null || true
done
