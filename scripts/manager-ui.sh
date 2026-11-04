#!/usr/bin/env bash
# Presentation only. Safe to source without Docker, root or a configured bot.
ui_init() {
  UI_RESET='' UI_TITLE='' UI_DIM='' UI_KEY='' UI_TEXT='' UI_OK='' UI_BAD='' UI_WARN='' UI_ACCENT='' UI_BORDER=''
  UI_TTY=0; [[ -t 1 && ${TERM:-dumb} != dumb && ${CIRO_ASCII:-0} != 1 ]] && UI_TTY=1
  if [[ -z ${NO_COLOR+x} && ( $UI_TTY == 1 || ${CLICOLOR_FORCE:-0} == 1 ) ]]; then
    UI_RESET=$'\033[0m'; UI_TITLE=$'\033[1;97m'; UI_DIM=$'\033[38;5;245m'
    UI_KEY=$'\033[1;36m'; UI_TEXT=$'\033[0;37m'; UI_OK=$'\033[1;32m'
    UI_BAD=$'\033[1;31m'; UI_WARN=$'\033[1;33m'; UI_ACCENT=$'\033[38;5;39m'; UI_BORDER=$'\033[38;5;24m'
  fi
  UI_RULE='─'; UI_HEAVY='━'; UI_MARK='◆'; UI_SEP='│'; UI_ARROW='❯'; UI_DOT='●'; UI_CHECK='✔'; UI_CROSS='✘'
  if [[ ${CIRO_ASCII:-0} == 1 || ${TERM:-dumb} == dumb ]]; then
    UI_RULE='-'; UI_HEAVY='='; UI_MARK='*'; UI_SEP='|'; UI_ARROW='>'; UI_DOT='o'; UI_CHECK='OK'; UI_CROSS='X'
  fi
  local columns=${COLUMNS:-80}
  [[ $columns =~ ^[0-9]+$ ]] || columns=80
  UI_WIDTH=$((columns-4)); ((UI_WIDTH>60)) && UI_WIDTH=60
  ((UI_WIDTH<24)) && UI_WIDTH=24
  return 0
}
ui_repeat() { local i; for ((i=0;i<$2;i++)); do printf '%s' "$1"; done; }
ui_rule() { printf '  %s' "$UI_BORDER"; ui_repeat "$UI_RULE" "$UI_WIDTH"; printf '%s\n' "$UI_RESET"; }
ui_banner() {
  printf '\n  %s' "$UI_ACCENT"; ui_repeat "$UI_HEAVY" "$UI_WIDTH"; printf '%s\n' "$UI_RESET"
  printf '  %s%s%s  %sCIRO DOWNLOADER%s\n' "$UI_ACCENT" "$UI_MARK" "$UI_RESET" "$UI_TITLE" "$UI_RESET"
  ui_note 'video and audio, delivered in Telegram'
  printf '  %s' "$UI_ACCENT"; ui_repeat "$UI_HEAVY" "$UI_WIDTH"; printf '%s\n' "$UI_RESET"
}
ui_screen() { [[ $UI_TTY == 0 ]] || printf '\033[2J\033[H'; ui_banner; }
ui_section() { printf '\n  %s%s%s %s%s%s\n' "$UI_ACCENT" "$UI_SEP" "$UI_RESET" "$UI_TITLE" "$1" "$UI_RESET"; ui_rule; }
ui_note() { local text; while IFS= read -r text; do printf '    %s%s%s\n' "$UI_DIM" "$text" "$UI_RESET"; done < <(printf '%s\n' "$1" | fold -s -w "$((UI_WIDTH-2))"); }
ui_ok() { printf '\n  %s%s %s%s\n' "$UI_OK" "$UI_CHECK" "$1" "$UI_RESET"; }
ui_warn() { printf '\n  %s! %s%s\n' "$UI_WARN" "$1" "$UI_RESET"; }
ui_error() { printf '\n  %s%s %s%s\n' "$UI_BAD" "$UI_CROSS" "$1" "$UI_RESET" >&2; }
ui_kv() { printf '    %s%-12s%s %s\n' "$UI_DIM" "$1" "$UI_RESET" "$2"; }
ui_row() {
  local key=$1 label=$2 description=${3:-} color=${4:-$UI_TEXT}
  printf '   %s%2s%s %s%s%s %s%-13s%s' "$UI_KEY" "$key" "$UI_RESET" "$UI_BORDER" "$UI_SEP" "$UI_RESET" "$color" "$label" "$UI_RESET"
  if [[ -n $description && $UI_WIDTH -ge 58 ]]; then
    printf ' %s%s%s\n' "$UI_DIM" "$description" "$UI_RESET"
  else
    printf '\n'; [[ -z $description ]] || ui_note "$description"
  fi
}
ui_prompt() { printf '\n  %s%s%s %s' "$UI_KEY" "$UI_ARROW" "$UI_RESET" "$1"; }
ui_pause() { local ignored; ui_prompt 'Press Enter to return... '; read -r ignored || true; }
ui_status() {
  local color=$UI_DIM
  case "$2" in Running|Ready|Active|Configured) color=$UI_OK ;; Stopped|Disabled|Partial) color=$UI_WARN ;; Unavailable|Failed) color=$UI_BAD ;; esac
  printf '    %s%-12s%s %s%s %s%s\n' "$UI_DIM" "$1" "$UI_RESET" "$color" "$UI_DOT" "$2" "$UI_RESET"
}
ui_bar() {
  local pct=$1 filled=$(( $1*14/100 )) i full='█' empty='░'
  if [[ ${CIRO_ASCII:-0} == 1 || ${TERM:-dumb} == dumb ]]; then full='#'; empty='.'; fi
  for ((i=0;i<14;i++)); do if ((i<filled)); then printf '%s' "$full"; else printf '%s' "$empty"; fi; done
}
ui_plan() { STEP_TOTAL=$1; STEP_DONE=0; }
ui_step_start() { printf '  %s[%s/%s]%s %s\n' "$UI_DIM" "$((STEP_DONE+1))" "$STEP_TOTAL" "$UI_RESET" "$1"; }
ui_step_progress() {
  # Keep animation on one short line even in a narrow mobile SSH terminal.
  printf '  %s%s%s %s[%s/%s]%s %ss' "$UI_KEY" "$1" "$UI_RESET" "$UI_DIM" "$((STEP_DONE+1))" "$STEP_TOTAL" "$UI_RESET" "$2"
}
ui_step_end() {
  local label=$1 status=$2 seconds=$3
  if ((status==0)); then
    STEP_DONE=$((STEP_DONE+1))
    printf '  %s%s%s %s[%s/%s]%s %s %s(%ss)%s\n' "$UI_OK" "$UI_CHECK" "$UI_RESET" "$UI_DIM" "$STEP_DONE" "$STEP_TOTAL" "$UI_RESET" "$label" "$UI_DIM" "$seconds" "$UI_RESET"
  else printf '  %s%s%s %s %s(%ss)%s\n' "$UI_BAD" "$UI_CROSS" "$UI_RESET" "$label" "$UI_DIM" "$seconds" "$UI_RESET"; fi
}
