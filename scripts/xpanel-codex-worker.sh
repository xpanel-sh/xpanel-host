#!/usr/bin/env bash
set -Eeuo pipefail
umask 077

job_dir="$1"
site_dir="$2"
thread="${3:-new}"
export CODEX_HOME="$4"
export HOME="$5"
export PATH="/usr/local/bin:/usr/bin:/bin:$PATH"
cd "$site_dir"
printf 'running\n' > "$job_dir/state"
args=(exec --json --skip-git-repo-check --sandbox workspace-write --output-last-message "$job_dir/answer")
if [[ "$thread" != new ]]; then
  args=(exec resume --json --skip-git-repo-check -c 'sandbox_mode="workspace-write"' --output-last-message "$job_dir/answer" "$thread")
else
  args+=(-C "$site_dir")
fi
args+=(-)
if codex "${args[@]}" < "$job_dir/prompt" > "$job_dir/events" 2> "$job_dir/error"; then
  printf 'done\n' > "$job_dir/state"
else
  printf 'failed\n' > "$job_dir/state"
fi
