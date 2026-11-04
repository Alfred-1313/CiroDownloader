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
if grep -q $'\033' "$test_root/menu"; then printf 'FAIL plain output has terminal escapes\n' >&2; exit 1; fi
export COLUMNS=40
ui_init; render_menu >"$test_root/narrow"
check awk 'length($0)>40 {exit 1}' "$test_root/narrow"
ui_plan 1
ui_step_progress '/' 1631 >"$test_root/narrow-progress"
check awk 'length($0)>40 {exit 1}' "$test_root/narrow-progress"
check grep -q '\[1/1\] 1631s' "$test_root/narrow-progress"
if grep -q '%' "$test_root/narrow-progress"; then printf 'FAIL misleading compiler percentage\n' >&2; exit 1; fi
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
