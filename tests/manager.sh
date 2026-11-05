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
check grep -q 'Private log:' "$test_root/progress"
ui_plan 1
run_step --live 'Live input fixture' cat <"$test_root/input" >"$test_root/live-progress"
check cmp -s "$test_root/input" "$LAST_STEP_LOG"
check grep -q 'COOKIE_INPUT_FIXTURE' "$test_root/live-progress"
check test "$STEP_DONE" -eq 1
ui_plan 1
if run_step 'Expected failure' bash -c 'printf "failure fixture\n" >&2; exit 7' >"$test_root/failure" 2>&1; then exit 1; else result=$?; fi
check test "$result" -eq 7
check grep -q 'This step failed' "$test_root/failure"
check grep -q 'Private log:' "$test_root/failure"

# The terminal must receive output while the build is still running, and retain
# the real command's failure code after the live log follower exits.
python3 -B - "$PROJECT_DIR/manager.sh" "$test_root" <<'PY'
import os
from pathlib import Path
import select
import signal
import subprocess
import sys
import time

script = '''source "$1"
export NO_COLOR=1 CIRO_ASCII=1 TERM=dumb
ui_init
LOG_DIR="$2/logs"
ui_plan 1
run_step --live 'Compiler fixture' bash -c 'printf "compiler-live-fixture\\n"; while [[ ! -f "$1/finish" ]]; do sleep 0.05; done; printf "compiler-error-fixture\\n" >&2; exit 7' _ "$2"
'''
with subprocess.Popen(['bash', '-c', script, '_', sys.argv[1], sys.argv[2]],
                      stdout=subprocess.PIPE, stderr=subprocess.STDOUT, start_new_session=True) as process:
    try:
        output = b''
        deadline = time.monotonic() + 5
        while b'compiler-live-fixture' not in output and time.monotonic() < deadline:
            if select.select([process.stdout], [], [], 0.1)[0]:
                part = os.read(process.stdout.fileno(), 4096)
                if not part:
                    break
                output += part
        assert b'compiler-live-fixture' in output, 'Manager hid the running compiler output'
        assert process.poll() is None, 'Build finished before live output was displayed'
        Path(sys.argv[2], 'finish').touch()
        remaining, _ = process.communicate(timeout=5)
        output += remaining
        assert process.returncode == 7, (process.returncode, output)
        assert b'compiler-error-fixture' in output, output
        assert b'This step failed' in output, output
    finally:
        if process.poll() is None:
            os.killpg(process.pid, signal.SIGKILL)
            process.wait()
print('PASS manager displays build output live and preserves failure status')
PY

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
check grep -q 'OPERATIONS' "$test_root/menu"
check grep -q 'DATA & SETTINGS' "$test_root/menu"
check grep -q '\[11\].*Auto-updates' "$test_root/menu"
if grep -q $'\033' "$test_root/menu"; then printf 'FAIL plain output has terminal escapes\n' >&2; exit 1; fi
export COLUMNS=40
ui_init; render_menu >"$test_root/narrow"
check awk 'length($0)>40 {exit 1}' "$test_root/narrow"
ui_plan 1
ui_step_progress '/' 1631 >"$test_root/narrow-progress"
check awk 'length($0)>40 {exit 1}' "$test_root/narrow-progress"
check grep -q '\[1/1\] 1631s' "$test_root/narrow-progress"
if grep -q '%' "$test_root/narrow-progress"; then printf 'FAIL misleading compiler percentage\n' >&2; exit 1; fi

# Whole screens, long paths, prompts and operation feedback fit mobile SSH widths.
for columns in 28 32 40 60 80; do
  export COLUMNS=$columns
  ui_init
  {
    render_menu
    show_help
    ui_section 'Main menu / Diagnostics'
    ui_prompt 'Administrator IDs, separated by commas (Enter to go back): '
    printf '\n'
    ui_status Configuration 'Not installed'
    ui_error 'This step failed. Completed steps may already have run.'
    ui_warn 'An update or another manager operation is running. Try again shortly.'
    ui_plan 1
    ui_step_start 'Build local Telegram API and migrate bot'
    ui_step_end 'Build local Telegram API and migrate bot' 0 1631
  } >"$test_root/width-$columns" 2>&1
  check awk -v width="$columns" 'length($0)>width {print "Overflow: " $0; exit 1}' "$test_root/width-$columns"
done

# The real output TTY wins over a stale/exported COLUMNS value, including after
# resizing, when Bash is noninteractive and stdin is not a terminal.
python3 -B - "$PROJECT_DIR/manager.sh" <<'PY'
import fcntl
import os
import pty
import struct
import subprocess
import sys
import termios

master, slave = pty.openpty()
try:
    fcntl.ioctl(slave, termios.TIOCSWINSZ, struct.pack('HHHH', 24, 80, 0, 0))
    script = '''source "$1"
ui_init
printf 'INITIAL_WIDTH=%s\\n' "$UI_WIDTH"
stty cols 32 <&1
ui_screen
printf 'RESIZED_WIDTH=%s\\n' "$UI_WIDTH"
'''
    subprocess.run(['bash', '-c', script, '_', sys.argv[1]], input=b'',
                   stdout=slave, stderr=slave, env=dict(os.environ, COLUMNS='99'),
                   check=True, timeout=5)
    output = os.read(master, 4096).decode()
    assert 'INITIAL_WIDTH=76' in output, output
    assert 'RESIZED_WIDTH=28' in output, output
finally:
    os.close(master)
    os.close(slave)
print('PASS actual TTY width and resize with piped input')
PY

# A failed Compose query is unknown, not evidence that a service is uninstalled.
(
  docker() { return 1; }
  render_status
) >"$test_root/status-error"
check grep -q 'Bot.*Unknown' "$test_root/status-error"
check grep -q 'Worker.*Unknown' "$test_root/status-error"
(
  docker() { printf 'other-bot\nworker-helper\n'; }
  render_status
) >"$test_root/status-names"
check grep -q 'Bot.*Not installed' "$test_root/status-names"
check grep -q 'Worker.*Not installed' "$test_root/status-names"
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

# An invalid selection must not consume the next valid selection as a pause.
printf 'wrong\n99\n4\nwrong\n0\n0\n' | menu >"$test_root/invalid-navigation"
check grep -q 'Main menu / Services' "$test_root/invalid-navigation"
check grep -q 'Bye' "$test_root/invalid-navigation"
check test "$(grep -c 'Enter a number' "$test_root/invalid-navigation")" -eq 3
printf 'wrong\n' | menu >"$test_root/invalid-eof"
check test "$(grep -c 'CIRO DOWNLOADER' "$test_root/invalid-eof")" -eq 1
printf '04\n' | (ui_read_choice 7 choice; test "$choice" = 4)

# Initial setup corrects typos inline, hides the token, and saves only at the end.
mkdir "$test_root/configuration"
(
  PROJECT_DIR="$test_root/configuration"
  printf 'invalid\n123:PRIVATE_TEST_TOKEN\n@admin\n42,99\n' | configure_initial
) >"$test_root/wizard" 2>&1
check grep -q '^ADMIN_IDS=42,99$' "$test_root/configuration/.env"
if grep -q PRIVATE_TEST_TOKEN "$test_root/wizard"; then printf 'FAIL token exposed in setup output\n' >&2; exit 1; fi
check test "$(stat -c %a "$test_root/configuration/.env")" = 600
mkdir "$test_root/cancelled"
for input in '' '123:PRIVATE_TEST_TOKEN'; do
  if (
    PROJECT_DIR="$test_root/cancelled"
    printf '%s\n\n' "$input" | configure_initial
  ) >"$test_root/wizard-cancel" 2>&1; then printf 'FAIL cancelled setup succeeded\n' >&2; exit 1; fi
  check test ! -e "$test_root/cancelled/.env"
  check grep -q 'Setup cancelled' "$test_root/wizard-cancel"
done

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
