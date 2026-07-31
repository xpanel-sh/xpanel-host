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

# Read mount points straight from the kernel's own table instead of piping
# through `findmnt`: on a host whose mount table has grown large (e.g. from
# repeated failed/retried mount attempts stacking bind mounts on top of each
# other -- `mount --bind` never fails just because the target is already
# mounted), findmnt's per-entry filesystem/label resolution can take minutes
# and burn CPU while every caller waits on it. Field 5 of mountinfo is the
# mount point; nothing else about the matching logic below changes.
while IFS= read -r target; do
  case "$target" in
    "$jail" | "$jail"/*)
      umount "$target" 2>/dev/null || umount -l "$target" 2>/dev/null || true
      ;;
  esac
done < <(awk '{ print $5 }' /proc/self/mountinfo | sort -r)
