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
  ui_measure
}
ui_measure() {
  local columns=${COLUMNS:-80} dimensions terminal_rows
  # Noninteractive Bash does not populate COLUMNS in many SSH sessions. Query
  # the output TTY, including when input is piped, and recheck after a resize.
  if [[ -t 1 ]] && dimensions=$(stty size <&1 2>/dev/null); then read -r terminal_rows columns <<<"$dimensions"; fi
  [[ $columns =~ ^[0-9]+$ ]] || columns=80
  UI_WIDTH=$((columns-4)); ((UI_WIDTH>76)) && UI_WIDTH=76
  ((UI_WIDTH<20)) && UI_WIDTH=20
  return 0
}
ui_wrap() {
  local text=$1 color=$2 indent=${3:-4} line
  while IFS= read -r line; do printf '%*s%s%s%s\n' "$indent" '' "$color" "$line" "$UI_RESET"; done \
    < <(printf '%s\n' "$text" | fold -s -w "$((UI_WIDTH+2-indent))")
}
ui_repeat() { local i; for ((i=0;i<$2;i++)); do printf '%s' "$1"; done; }
ui_rule() { printf '  %s' "$UI_BORDER"; ui_repeat "$UI_RULE" "$UI_WIDTH"; printf '%s\n' "$UI_RESET"; }
ui_banner() {
  printf '\n  %s' "$UI_ACCENT"; ui_repeat "$UI_HEAVY" "$UI_WIDTH"; printf '%s\n' "$UI_RESET"
  printf '  %s%s%s  %sCIRO DOWNLOADER%s\n' "$UI_ACCENT" "$UI_MARK" "$UI_RESET" "$UI_TITLE" "$UI_RESET"
  ui_note 'video and audio, delivered in Telegram'
  printf '  %s' "$UI_ACCENT"; ui_repeat "$UI_HEAVY" "$UI_WIDTH"; printf '%s\n' "$UI_RESET"
}
ui_screen() { ui_measure; [[ $UI_TTY == 0 ]] || printf '\033[2J\033[H'; ui_banner; }
ui_section() { printf '\n'; ui_wrap "$UI_SEP $1" "$UI_TITLE" 2; ui_rule; }
ui_group() { printf '\n'; ui_wrap "$1" "$UI_DIM" 2; }
ui_note() { ui_wrap "$1" "$UI_DIM"; }
ui_ok() { printf '\n'; ui_wrap "$UI_CHECK $1" "$UI_OK" 2; }
ui_warn() { printf '\n'; ui_wrap "! $1" "$UI_WARN" 2; }
ui_error() { printf '\n' >&2; ui_wrap "$UI_CROSS $1" "$UI_BAD" 2 >&2; }
ui_kv() {
  if ((17+${#2}<=UI_WIDTH+2)); then printf '    %s%-12s%s %s\n' "$UI_DIM" "$1" "$UI_RESET" "$2"
  else ui_wrap "$1" "$UI_DIM"; ui_wrap "$2" "$UI_TEXT" 6; fi
}
ui_row() {
  local key=$1 label=$2 description=${3:-} color=${4:-$UI_TEXT}
  printf '  %s[%2s]%s  %s%-14s%s' "$UI_KEY" "$key" "$UI_RESET" "$color" "$label" "$UI_RESET"
  if [[ -n $description ]] && ((23+${#description}<=UI_WIDTH+2)); then
    printf ' %s%s%s\n' "$UI_DIM" "$description" "$UI_RESET"
  else
    printf '\n'; [[ -z $description ]] || ui_wrap "$description" "$UI_DIM" 8
  fi
}
ui_prompt() {
  printf '\n'
  if ((${#1}+4>UI_WIDTH+2)); then ui_wrap "$1" "$UI_TEXT" 2; printf '  %s%s%s ' "$UI_KEY" "$UI_ARROW" "$UI_RESET"
  else printf '  %s%s%s %s' "$UI_KEY" "$UI_ARROW" "$UI_RESET" "$1"; fi
}
ui_read_choice() {
  local max=$1 target=$2 value
  while true; do
    ui_prompt "Choose [0-$max]: "; read -r value || return 1
    if [[ $value =~ ^[0-9]{1,2}$ ]] && ((10#$value<=max)); then printf -v "$target" '%s' "$((10#$value))"; return 0; fi
    ui_warn "Enter a number from 0 to $max."
  done
}
ui_pause() { local ignored; ui_prompt 'Press Enter to return... '; read -r ignored || true; }
ui_status() {
  local color=$UI_DIM
  case "$2" in Running|Ready|Active|Configured) color=$UI_OK ;; Stopped|Disabled|Partial|Missing|Unknown) color=$UI_WARN ;; Unavailable|Failed) color=$UI_BAD ;; esac
  if ((19+${#2}<=UI_WIDTH+2)); then
    printf '    %s%-12s%s %s%s %s%s\n' "$UI_DIM" "$1" "$UI_RESET" "$color" "$UI_DOT" "$2" "$UI_RESET"
  else ui_wrap "$1" "$UI_DIM"; ui_wrap "$UI_DOT $2" "$color" 6; fi
}
ui_bar() {
  local pct=$1 filled=$(( $1*14/100 )) i full='█' empty='░'
  if [[ ${CIRO_ASCII:-0} == 1 || ${TERM:-dumb} == dumb ]]; then full='#'; empty='.'; fi
  for ((i=0;i<14;i++)); do if ((i<filled)); then printf '%s' "$full"; else printf '%s' "$empty"; fi; done
}
ui_plan() { STEP_TOTAL=$1; STEP_DONE=0; }
ui_step_start() { ui_wrap "[$((STEP_DONE+1))/$STEP_TOTAL] $1" "$UI_TEXT" 2; }
ui_step_progress() {
  # Keep animation on one short line even in a narrow mobile SSH terminal.
  printf '  %s%s%s %s[%s/%s]%s %ss' "$UI_KEY" "$1" "$UI_RESET" "$UI_DIM" "$((STEP_DONE+1))" "$STEP_TOTAL" "$UI_RESET" "$2"
}
ui_step_end() {
  local label=$1 status=$2 seconds=$3
  if ((status==0)); then
    STEP_DONE=$((STEP_DONE+1))
    ui_wrap "$UI_CHECK [$STEP_DONE/$STEP_TOTAL] $label (${seconds}s)" "$UI_OK" 2
  else ui_wrap "$UI_CROSS $label (${seconds}s)" "$UI_BAD" 2; fi
}
