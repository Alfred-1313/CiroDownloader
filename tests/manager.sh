#!/usr/bin/env bash
set -Eeuo pipefail
source "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd)/manager.sh"
export NO_COLOR=1 CIRO_ASCII=1 TERM=dumb
ui_init
test_root="$(mktemp -d "$PROJECT_DIR/tests/.manager-test-XXXXXXXX")"
test_root="$(cd -- "$test_root" && pwd -P)"
[[ $test_root == "$PROJECT_DIR/tests/.manager-test-"* ]] || exit 1
trap 'rm -rf -- "$test_root"' EXIT
LOG_DIR="$test_root/logs"; LOCK_FILE="$test_root/lock"
check() { if "$@"; then printf 'PASS %s\n' "$1"; else printf 'FAIL %s\n' "$*" >&2; exit 1; fi; }

# Real background process I/O; no Docker or host changes.
printf 'COOKIE_INPUT_FIXTURE\n' >"$test_root/input"
ui_plan 1
run_step 'Read a supplied document' cat <"$test_root/input" >"$test_root/progress"
check cmp -s "$test_root/input" "$LAST_STEP_LOG"
check grep -q '\[1/1\]' "$test_root/progress"
ui_plan 1
if run_step 'Expected failure' bash -c 'printf "failure fixture\n" >&2; exit 7' >"$test_root/failure" 2>&1; then exit 1; else result=$?; fi
check test "$result" -eq 7
check grep -q 'This step failed' "$test_root/failure"
check grep -q 'Private log:' "$test_root/failure"

docker_ready() { return 0; }
timeout() { shift; "$@"; }
docker() {
  case "$*" in
    'compose ps --status running --services') printf 'bot\n' ;;
    'compose ps --all --services') printf 'bot\nworker\n' ;;
    *) printf 'Unexpected Docker mutation: %s\n' "$*" >&2; return 99 ;;
  esac
}
render_menu >"$test_root/menu"
check grep -q 'CIRO DOWNLOADER' "$test_root/menu"
check grep -q 'Bot.*Running' "$test_root/menu"
check grep -q 'Worker.*Stopped' "$test_root/menu"
check grep -q 'Backup' "$test_root/menu"
check grep -q 'Restore' "$test_root/menu"
check grep -q 'Settings' "$test_root/menu"
if grep -q $'\033' "$test_root/menu"; then printf 'FAIL plain output has terminal escapes\n' >&2; exit 1; fi
export COLUMNS=40
ui_init; render_menu >"$test_root/narrow"
check awk 'length($0)>40 {exit 1}' "$test_root/narrow"
export COLUMNS=80
unset NO_COLOR
export CLICOLOR_FORCE=1 TERM=xterm-256color CIRO_ASCII=0
ui_init; ui_banner >"$test_root/colors"
check grep -q $'\033\[38;5;39m' "$test_root/colors"
export NO_COLOR='' CIRO_ASCII=1 TERM=dumb
ui_init; ui_banner >"$test_root/no-color"
if grep -q $'\033' "$test_root/no-color"; then exit 1; fi

# Back returns to the parent; EOF terminates instead of recurring forever.
printf '4\n0\n8\n0\n10\n0\n0\n' | menu >"$test_root/navigation"
check grep -q 'Bye' "$test_root/navigation"
menu </dev/null >"$test_root/eof"
check test "$(grep -c 'CIRO DOWNLOADER' "$test_root/eof")" -eq 1

# Stop and removal cancellation must not run any Docker command.
printf '\n' | delete_bot >"$test_root/cancel"
check grep -q 'Nothing was changed' "$test_root/cancel"
if command -v flock >/dev/null 2>&1; then
  (exec 8>"$LOCK_FILE"; flock 8; touch "$test_root/locked"; sleep 1) & holder=$!
  while [[ ! -f $test_root/locked ]]; do sleep 0.02; done
  if with_lock touch "$test_root/incorrect" >"$test_root/lock-output"; then exit 1; fi
  check test ! -e "$test_root/incorrect"
  wait "$holder"
fi
printf 'PASS manager navigation, status, progress and lock checks\n'
