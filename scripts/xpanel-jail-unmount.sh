#!/usr/bin/env bash
# Recursively tears down every bind mount under a per-site terminal jail.
#
# SAFETY-CRITICAL, read before touching this file: an earlier version used
# `findmnt -R --target "$jail"` to enumerate mounts to tear down. $jail is
# never itself a distinct mountpoint (only paths *inside* it are, e.g.
# $jail/bin, $jail/etc/php) -- so findmnt resolves --target to the nearest
# covering mount, which is the root filesystem itself, and -R then lists
# EVERY mount on the whole machine as a "submount" of that root (/proc,
# /sys, /dev, everything). That took down a live server's /proc. Never
# resolve the jail path through findmnt's --target/-R matching again --
# enumerate the full mount list unfiltered and keep only exact,
# literal-string-prefix matches in bash, which cannot expand beyond the
# given path regardless of what findmnt thinks "covers" it.
set -uo pipefail

jail="${1:?usage: xpanel-jail-unmount.sh <jail-path>}"

# Second, independent guard: refuse to touch anything that isn't clearly
# one of our own per-site jail directories, no matter how $jail was computed.
case "$jail" in
  /var/lib/xpanel-host/jails/*) ;;
  *)
    echo "xpanel-jail-unmount.sh: refusing to operate on suspicious path: $jail" >&2
    exit 1
    ;;
esac

while IFS= read -r target; do
  case "$target" in
    "$jail" | "$jail"/*)
      umount "$target" 2>/dev/null || umount -l "$target" 2>/dev/null || true
      ;;
  esac
done < <(findmnt -n -o TARGET 2>/dev/null | sort -r)
