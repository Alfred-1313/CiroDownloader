#!/usr/bin/env bash
# Ciro Downloader manager. The Ciro/VPN project is never a target of this script.
set -Eeuo pipefail
umask 077

REPOSITORY_URL='https://github.com/Alfred-1313/CiroDownloader.git'
INSTALLER_VERSION='3.0.1'
PROJECT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
LOG_DIR='/var/log/ciro-downloader'
BACKUP_DIR='/var/backups/ciro-downloader'
LOCK_FILE='/run/ciro-downloader-updater.lock'
SECRET_TMP='' RUN_PID='' RUN_LOG_PID='' LAST_STEP_LOG=''
STEP_TOTAL=1 STEP_DONE=0
source "$PROJECT_DIR/scripts/manager-ui.sh"

cleanup() {
  [[ -z $SECRET_TMP ]] || rm -f -- "$SECRET_TMP"
  if [[ -n $RUN_LOG_PID ]]; then kill "$RUN_LOG_PID" 2>/dev/null || true; wait "$RUN_LOG_PID" 2>/dev/null || true; fi
  [[ ${UI_TTY:-0} == 0 ]] || printf '\033[?25h'
}
interrupted() { [[ -z $RUN_PID ]] || kill "$RUN_PID" 2>/dev/null || true; ui_error 'Operation interrupted. Check Status before retrying.'; exit 130; }

run_step() {
  local live=0
  if [[ ${1:-} == --live ]]; then live=1; shift; fi
  local label=$1; shift
  [[ -d $LOG_DIR ]] || mkdir -m 700 -- "$LOG_DIR" || return
  LAST_STEP_LOG="$(mktemp "$LOG_DIR/step-XXXXXXXX.log")" || return
  local started=$SECONDS result=0 frame=0 frames=('-' '\\' '|' '/')
  ui_step_start "$label"
  ui_note "Private log: $LAST_STEP_LOG"
  "$@" <&0 >"$LAST_STEP_LOG" 2>&1 & RUN_PID=$!
  if ((live)); then
    # Follow only explicitly selected output; configuration steps stay private.
    tail --pid="$RUN_PID" --sleep-interval=0.2 -n +1 -f -- "$LAST_STEP_LOG" & RUN_LOG_PID=$!
  elif [[ $UI_TTY == 1 ]]; then
    printf '\033[?25l'
    while kill -0 "$RUN_PID" 2>/dev/null; do
      printf '\r\033[K'
      ui_step_progress "${frames[$frame]}" "$((SECONDS-started))"
      frame=$(((frame+1)%4)); sleep 0.2
    done
    printf '\r\033[K\033[?25h'
  fi
  if wait "$RUN_PID"; then result=0; else result=$?; fi
  RUN_PID=''
  if [[ -n $RUN_LOG_PID ]]; then wait "$RUN_LOG_PID" || true; RUN_LOG_PID=''; fi
  ui_step_end "$label" "$result" "$((SECONDS-started))"
  if ((result!=0)); then
    ui_error 'This step failed. Completed steps may already have run.'
    if ((!live)); then tail -n 8 -- "$LAST_STEP_LOG" >&2; fi
    ui_note "Private log: $LAST_STEP_LOG"
  fi
  return "$result"
}

with_lock() {
  exec 9>"$LOCK_FILE" || { ui_error 'Cannot open the maintenance lock.'; return 1; }
  if ! flock -n 9; then exec 9>&-; ui_warn 'An update or another manager operation is running. Try again shortly.'; return 1; fi
  export CIRO_MANAGER_LOCK_FD=9
  local result=0
  if "$@"; then result=0; else result=$?; fi
  unset CIRO_MANAGER_LOCK_FD
  flock -u 9; exec 9>&-
  return "$result"
}

docker_ready() {
  command -v docker >/dev/null 2>&1 && timeout 5 docker compose version >/dev/null 2>&1 && timeout 5 docker info >/dev/null 2>&1
}
assert_project() {
  [[ -f compose.yaml ]] && grep -Eq '^name: *ciro-downloader *$' compose.yaml || { ui_error 'This is not the Ciro Downloader installation.'; return 1; }
  if [[ -d .git ]]; then
    local origin
    origin=$(git remote get-url origin) || return
    [[ ${origin%.git} == ${REPOSITORY_URL%.git} ]] || { ui_error 'This checkout belongs to another repository.'; return 1; }
  fi
}
require_python() {
  command -v python3 >/dev/null 2>&1 && return 0
  command -v apt-get >/dev/null 2>&1 || { ui_error 'Python 3 is required. Install it and retry.'; return 1; }
  ui_plan 2
  run_step 'Refresh package index' apt-get update || return
  run_step 'Install Python 3' apt-get install -y python3 || return
}
require_docker() {
  if ! command -v docker >/dev/null 2>&1; then
    [[ ${1:-} == install ]] || { ui_error 'Docker is missing. Choose Install first.'; return 1; }
    command -v apt-get >/dev/null 2>&1 || { ui_error 'Install Docker Engine and its Compose plugin first.'; return 1; }
    ui_plan 2
    if ! command -v curl >/dev/null 2>&1; then
      ui_plan 4
      run_step 'Refresh package index' apt-get update || return
      run_step 'Install download tools' apt-get install -y curl ca-certificates || return
    fi
    local installer
    installer="$(mktemp)" || return
    if ! run_step 'Download Docker installer' curl -fsSL https://get.docker.com -o "$installer"; then rm -f -- "$installer"; return 1; fi
    if ! run_step 'Install Docker Engine' sh "$installer"; then rm -f -- "$installer"; return 1; fi
    rm -f -- "$installer"
  fi
  docker_ready || { ui_error 'Docker Engine or the Compose plugin is unavailable. Check the daemon and retry.'; return 1; }
}

configure_initial() {
  [[ ! -f .env ]] || return 0
  ui_section 'Connect your bot'
  ui_note 'The token is hidden. Existing bot settings are kept on later runs.'
  local token ids
  ui_prompt 'Telegram bot token: '; read -rs token || return 1; printf '\n'
  [[ $token =~ ^[0-9]+:[A-Za-z0-9_-]+$ ]] || { ui_error 'Invalid bot token.'; return 1; }
  ui_prompt 'Administrator IDs, separated by commas: '; read -r ids || return 1
  [[ $ids =~ ^[1-9][0-9]*(,[1-9][0-9]*)*$ ]] || { ui_error 'Use numeric Telegram IDs separated by commas.'; return 1; }
  SECRET_TMP="$(mktemp "$PROJECT_DIR/.env.setup-XXXXXXXX")" || return
  printf 'BOT_TOKEN=%s\nADMIN_IDS=%s\nBOT_API_URL=https://api.telegram.org\nMAX_FILE_BYTES=49000000\nSTORAGE_DIR=/app/storage\nYT_DLP_BIN=/opt/yt/bin/yt-dlp\n' "$token" "$ids" >"$SECRET_TMP" || return
  chmod 600 "$SECRET_TMP"; mv -- "$SECRET_TMP" "$PROJECT_DIR/.env" || return
  SECRET_TMP=''; unset token ids
  ui_ok 'Bot configuration saved.'
}
require_configuration() { [[ -f .env ]] || { ui_warn 'Install the bot first to create its configuration.'; return 1; }; }
require_no_api_migration() {
  [[ ! -f /var/lib/ciro-downloader-telegram-api/migration.json ]] || { ui_error 'Local Telegram API migration is incomplete. Finish it in Settings > Large uploads first.'; return 1; }
}
validate_bot() {
  docker compose run --rm --no-deps -T bot php -r 'require "src/Core.php"; $api=new Zero\Telegram(Zero\Config::load()); $me=$api->call("getMe"); $hook=$api->call("getWebhookInfo"); if(!empty($hook["url"])) {fwrite(STDERR,"A webhook is configured. Use a dedicated bot token or remove the webhook first.\n");exit(1);} echo "Connected as @".$me["username"].PHP_EOL;'
}
install_shortcut() {
  local target='/usr/local/bin/CiroDownloader' wrapper
  if [[ -e $target || -L $target ]]; then
    if ! grep -q '^# Ciro Downloader manager shortcut$' "$target" 2>/dev/null; then
      ui_warn 'The CiroDownloader command already belongs to another installation; it was kept.'; return 0
    fi
  fi
  wrapper="$(mktemp)" || return
  printf '#!/usr/bin/env bash\n# Ciro Downloader manager shortcut\nexec bash %q "$@"\n' "$PROJECT_DIR/manager.sh" >"$wrapper" || return
  install -m 755 "$wrapper" "$target"; local result=$?; rm -f -- "$wrapper"; return "$result"
}
enable_telegram_updates() {
  if [[ $PROJECT_DIR == /opt/ciro-downloader ]] && command -v systemctl >/dev/null 2>&1; then
    bash scripts/install-updater.sh || return
  else ui_note 'Telegram updates require systemd and /opt/ciro-downloader.'; fi
}

install_bot() {
  ui_screen; ui_section 'Install Ciro Downloader'
  require_no_api_migration || return
  require_docker install || return
  configure_initial || return
  ui_plan 5
  run_step 'Build the application' docker compose build --pull || return
  run_step 'Verify the Telegram connection' validate_bot || return
  run_step 'Start bot and worker' docker compose up -d --no-build || return
  run_step 'Set up Telegram updates' enable_telegram_updates || return
  run_step 'Install CiroDownloader command' install_shortcut || return
  ui_ok 'Ciro Downloader is ready.'; ui_note 'Open your bot and send /start. Next time, run: CiroDownloader'
}
update_bot() {
  ui_screen; ui_section 'Update'
  require_no_api_migration || return
  require_configuration || return
  [[ -d .git && -z $(git status --porcelain) ]] || { ui_warn 'The checkout contains local changes or has no Git history. Nothing was fetched.'; return 1; }
  local origin
  origin=$(git remote get-url origin) || return
  [[ ${origin%.git} == ${REPOSITORY_URL%.git} ]] || { ui_error 'This checkout points to a different repository.'; return 1; }
  require_docker || return; require_python || return
  ui_plan 7
  run_step 'Back up data and configuration' python3 scripts/maintenance.py backup || return
  ui_note "$(tail -n 1 "$LAST_STEP_LOG")"
  run_step 'Fetch the latest release' git fetch origin main || return
  run_step 'Apply the release' git merge --ff-only origin/main || return
  run_step 'Build the new application' docker compose build --pull || return
  run_step 'Verify the Telegram connection' validate_bot || return
  run_step 'Start the new version' docker compose up -d --no-build || return
  run_step 'Refresh Telegram updates' enable_telegram_updates || return
  install_shortcut || return
  ui_ok 'Update complete. Your data and settings were kept.'
}
start_services() {
  require_no_api_migration || return
  ui_section 'Start services'; require_configuration || return; require_docker || return; ui_plan 1
  run_step 'Start bot and worker' docker compose up -d || return
  ui_ok 'Services started.'
}
stop_services() {
  ui_section 'Stop services'; require_docker || return; ui_plan 1
  run_step 'Stop bot and worker' docker compose stop || return
  ui_ok 'Services stopped. Data and settings were kept.'
}
restart_services() {
  require_no_api_migration || return
  ui_section 'Restart services'; require_configuration || return; require_docker || return; ui_plan 1
  run_step 'Restart bot and worker' docker compose restart || return
  ui_ok 'Services restarted.'
}
show_logs() {
  ui_section 'Service logs'; docker_ready || { ui_warn 'Docker is unavailable.'; return 1; }
  local service=${1:-all}
  if [[ $service == all ]]; then docker compose logs --tail=100
  else docker compose logs --tail=100 "$service"; fi
}
show_status() {
  ui_screen; ui_section 'Status'; render_status
  if docker_ready; then printf '\n'; docker compose ps --all; fi
  if command -v systemctl >/dev/null 2>&1; then
    ui_section 'Telegram updater'; systemctl status ciro-downloader-updater.timer --no-pager --lines=3 || true
  fi
  ui_note "Project: $PROJECT_DIR"; ui_note "Backups: $BACKUP_DIR"; ui_note "Private logs: $LOG_DIR"
}
backup_bot() {
  ui_screen; ui_section 'Backup'
  ui_note 'Briefly pauses this bot, then resumes the services that were running.'
  require_configuration || return; require_docker || return; require_python || return
  ui_plan 1
  run_step 'Save database, cookies and configuration' python3 scripts/maintenance.py backup || return
  ui_ok 'Backup complete.'; ui_note "$(tail -n 1 "$LAST_STEP_LOG")"
}
restore_bot() {
  ui_screen; ui_section 'Restore'
  require_configuration || return; require_docker || return; require_python || return
  ui_plan 1
  python3 scripts/maintenance.py list || return
  local archive=${1:-} confirmation
  if [[ -z $archive ]]; then ui_prompt 'Absolute backup path (Enter to go back): '; read -r archive || return 0; fi
  [[ -n $archive ]] || return 0
  [[ $archive == /* && -r $archive && -f $archive ]] || { ui_error 'Choose an absolute path to a readable backup.'; return 1; }
  ui_warn "This replaces this bot's data, cookies, token and administrator settings."
  ui_note 'The archive is validated first. A recovery backup is kept before replacement.'
  ui_prompt 'Type RESTORE to continue: '; read -r confirmation || return 0
  [[ $confirmation == RESTORE ]] || { ui_note 'Nothing was changed.'; return 0; }
  run_step 'Validate and restore the backup' python3 scripts/maintenance.py restore "$archive" || return
  ui_ok 'Restore complete.'; ui_note "$(tail -n 1 "$LAST_STEP_LOG")"
}
edit_setting() {
  local field=$1 value
  require_configuration || return; require_docker || return; require_python || return
  ui_section 'Edit setting'
  case "$field" in
    BOT_TOKEN) ui_note 'Input is hidden. A running bot is checked before the new token is applied.'; ui_prompt 'New bot token (Enter to go back): '; read -rs value || return 0; printf '\n' ;;
    ADMIN_IDS) ui_prompt 'Administrator IDs, separated by commas (Enter to go back): '; read -r value || return 0 ;;
    YOUTUBE_PROXY) ui_note 'Input is hidden. Use http(s)://host:port or socks5(h)://host:port.'; ui_note 'Type clear to disable the proxy.'; ui_prompt 'Proxy (Enter to go back): '; read -rs value || return 0; printf '\n' ;;
  esac
  [[ -n $value ]] || return 0
  [[ $field != YOUTUBE_PROXY || $value != clear ]] || value=''
  SECRET_TMP="$(mktemp)" || return
  printf '%s' "$value" >"$SECRET_TMP"; unset value
  ui_plan 1
  local result=0
  if run_step 'Save settings and refresh active services' python3 scripts/maintenance.py configure "$field" --input-file "$SECRET_TMP"; then ui_ok 'Setting saved. Previously running services were refreshed.'
  else result=$?; fi
  rm -f -- "$SECRET_TMP"; SECRET_TMP=''; return "$result"
}
enable_large_uploads() {
  ui_screen; ui_section 'Large Telegram uploads'
  require_configuration || return; require_docker || return; require_python || return
  ui_note 'Get your API ID and API hash at https://my.telegram.org/apps'
  ui_note 'The API hash is hidden and stored privately on this server.'
  ui_note 'A local Telegram server is built from official source; the first build can take a long time.'
  ui_warn 'The bot pauses during migration. The cloud API may be unavailable for 10 minutes after logout.'
  ui_note 'Large videos require free disk space for downloading, conversion and upload.'
  local api_id api_hash result=0
  ui_prompt 'API ID (Enter to go back): '; read -r api_id || return 0
  [[ -n $api_id ]] || return 0
  ui_prompt 'API hash: '; read -rs api_hash || return 0; printf '\n'
  SECRET_TMP="$(mktemp)" || return
  printf '%s\n%s\n' "$api_id" "$api_hash" >"$SECRET_TMP"; unset api_id api_hash
  ui_plan 1
  if run_step --live 'Build local Telegram API and migrate bot' python3 -u scripts/large_uploads.py "$SECRET_TMP"; then
    ui_ok 'Large uploads enabled. Send a video to test delivery.'
  else result=$?; fi
  rm -f -- "$SECRET_TMP"; SECRET_TMP=''; return "$result"
}
import_youtube_cookies() {
  ui_screen; ui_section 'Import YouTube cookies'
  require_configuration || return; require_docker || return
  ui_plan 1
  ui_note 'Use any .txt filename exported in Netscape format.'
  ui_note 'For access verification before replacement, import from the Telegram admin panel.'
  local cookie_file
  ui_prompt 'Absolute file path (Enter to go back): '; read -r cookie_file || return 0
  [[ -n $cookie_file ]] || return 0
  [[ $cookie_file == /* && -r $cookie_file && -f $cookie_file && ${cookie_file,,} == *.txt ]] || { ui_error 'Choose a readable .txt export using its absolute path.'; return 1; }
  run_step 'Import the private cookie file' docker compose run --rm --no-deps -T worker php bin/import-cookies.php <"$cookie_file" || return
  ui_ok 'Cookies imported. Use Diagnostics with a YouTube link to test delivery.'
}
run_diagnostics() {
  ui_screen; ui_section 'Delivery diagnostics'
  require_configuration || return; require_docker || return
  ui_plan 1
  ui_note 'A test video is sent to the first configured administrator.'
  local test_url
  ui_prompt 'Public YouTube URL (Enter for an upload-only test): '; read -r test_url || return 0
  if [[ -n $test_url ]]; then run_step 'Test YouTube download and Telegram upload' docker compose run --rm --no-deps -T -e "TEST_VIDEO_URL=$test_url" worker php bin/diagnose.php || return
  else run_step 'Test video preparation and Telegram upload' docker compose run --rm --no-deps -T worker php bin/diagnose.php || return; fi
  ui_ok 'Diagnostics passed. Check Telegram for the test video.'
}
doctor() {
  ui_screen; ui_section 'Server checks'; render_status
  ui_status 'Configuration' "$([[ -f .env ]] && printf Configured || printf Missing)"
  ui_status 'Python 3' "$(command -v python3 >/dev/null && printf Ready || printf Missing)"
  ui_status 'Disk space' "$(df -h "$PROJECT_DIR" | awk 'NR==2 {print $4 " available"}')"
  ui_status 'Git checkout' "$([[ -d .git ]] && printf Ready || printf Missing)"
  if [[ -f /var/lib/ciro-downloader-updater/recovery.json ]]; then ui_warn 'An interrupted Telegram update is waiting for recovery.'; fi
  ui_note 'These checks do not send a Telegram message. Choose Delivery test for a real upload.'
}
delete_bot() {
  ui_screen; ui_section 'Remove installation'; ui_plan 2
  ui_warn "Removes this bot's containers, image, data volume and local token file."
  ui_note 'Source code, private logs and recovery backups stay on the server.'
  local confirmation
  ui_prompt 'Type DELETE to continue (Enter to go back): '; read -r confirmation || return 0
  [[ $confirmation == DELETE ]] || { ui_note 'Nothing was changed.'; return 0; }
  docker_ready || { ui_error 'Docker is unavailable. Configuration and data were kept.'; return 1; }
  if command -v systemctl >/dev/null 2>&1; then
    systemctl disable --now ciro-downloader-updater.timer >/dev/null 2>&1 || true
    systemctl stop ciro-downloader-updater.service >/dev/null 2>&1 || true
    if mountpoint -q /run/ciro-downloader-data; then umount /run/ciro-downloader-data || return; fi
  fi
  if [[ -f /var/lib/ciro-downloader-telegram-api/api.env ]]; then
    run_step 'Remove the local Telegram API container' docker compose -f compose.telegram-api.yaml down --remove-orphans || return
  fi
  run_step "Remove this project's containers and data" docker compose down --volumes --remove-orphans || return
  docker image rm ciro-downloader:local >/dev/null 2>&1 || true
  run_step 'Remove the local token file' rm -f -- "$PROJECT_DIR/.env" || return
  rm -f -- /var/lib/ciro-downloader-telegram-api/api.env /var/lib/ciro-downloader-telegram-api/migration.json
  rm -f -- /var/lib/ciro-downloader-updater/recovery.json /var/lib/ciro-downloader-updater/completion-pending.json /var/lib/ciro-downloader-updater/previous.json
  ui_ok 'Ciro Downloader removed. Source code and backups were kept.'
}

render_status() {
  local bot='Not installed' worker='Not installed' running='' known=''
  if docker_ready; then
    running=$(timeout 5 docker compose ps --status running --services 2>/dev/null) || running=''
    known=$(timeout 5 docker compose ps --all --services 2>/dev/null) || known=''
    [[ $known != *bot* ]] || bot=Stopped; [[ $known != *worker* ]] || worker=Stopped
    [[ $running != *bot* ]] || bot=Running; [[ $running != *worker* ]] || worker=Running
  else bot=Unavailable; worker=Unavailable; fi
  local version='Not installed' updater=Disabled
  [[ ! -f VERSION ]] || version=$(tr -d '\r\n' <VERSION)
  if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet ciro-downloader-updater.timer 2>/dev/null; then updater=Active; fi
  ui_kv 'Version' "$version  (manager $INSTALLER_VERSION)"
  ui_status 'Bot' "$bot"; ui_status 'Worker' "$worker"; ui_status 'Auto-updates' "$updater"
}
render_menu() {
  ui_screen; render_status; ui_section 'Menu'
  ui_row 1 Install 'set up or rebuild the bot'
  ui_row 2 Update 'newest code, keeps your data'
  ui_row 3 Remove 'delete this bot and its data' "$UI_BAD"
  ui_rule
  ui_row 4 Services 'start, stop, restart and logs'
  ui_row 5 Status 'bot, worker and updater health'
  ui_row 6 Backup 'database, cookies and settings'
  ui_row 7 Restore 'recover a private backup'
  ui_row 8 Settings 'bot token, admins and proxy'
  ui_row 9 Cookies 'import a YouTube cookie file'
  ui_row 10 Diagnostics 'server checks and delivery test'
  ui_row 11 Telegram 'enable administrator updates'
  ui_row 12 Help 'commands and shortcuts'
  ui_row 0 Exit
  ui_rule
}
services_menu() {
  local choice
  while true; do
    ui_screen; ui_section 'Services'
    ui_row 1 Start 'start bot and worker'; ui_row 2 Stop 'keep all data and settings'; ui_row 3 Restart
    ui_row 4 'All logs'; ui_row 5 'Bot logs'; ui_row 6 'Worker logs'; ui_row 7 Status; ui_row 0 Back; ui_rule
    ui_prompt 'Choose [0-7]: '; read -r choice || return 0
    case "$choice" in 1) with_lock start_services || true ;; 2) with_lock stop_services || true ;; 3) with_lock restart_services || true ;; 4) show_logs || true ;; 5) show_logs bot || true ;; 6) show_logs worker || true ;; 7) show_status ;; 0) return ;; *) ui_warn 'Choose a number shown above.' ;; esac
    ui_pause
  done
}
settings_menu() {
  local choice
  while true; do
    ui_screen; ui_section 'Settings'
    if [[ -f .env ]] && command -v python3 >/dev/null 2>&1; then python3 scripts/maintenance.py summary || true; fi
    ui_row 1 'Bot token' 'hidden input, checked before apply'
    ui_row 2 Administrators 'Telegram IDs separated by commas'
    ui_row 3 'YouTube proxy' 'set, change or disable'
    ui_row 4 'Large uploads' 'enable a private local Telegram Bot API'
    ui_row 0 Back; ui_rule
    ui_prompt 'Choose [0-4]: '; read -r choice || return 0
    case "$choice" in 1) with_lock edit_setting BOT_TOKEN || true ;; 2) with_lock edit_setting ADMIN_IDS || true ;; 3) with_lock edit_setting YOUTUBE_PROXY || true ;; 4) with_lock enable_large_uploads || true ;; 0) return ;; *) ui_warn 'Choose a number shown above.' ;; esac
    ui_pause
  done
}
diagnostics_menu() {
  local choice
  while true; do
    ui_screen; ui_section 'Diagnostics'; ui_row 1 'Server checks' 'no Telegram messages'; ui_row 2 'Delivery test' 'sends a test video to your admin'; ui_row 0 Back; ui_rule
    ui_prompt 'Choose [0-2]: '; read -r choice || return 0
    case "$choice" in 1) doctor ;; 2) with_lock run_diagnostics || true ;; 0) return ;; *) ui_warn 'Choose a number shown above.' ;; esac
    ui_pause
  done
}
show_help() {
  ui_screen; ui_section 'Commands'
  ui_kv 'Menu' 'CiroDownloader'; ui_kv 'Install' 'CiroDownloader install'; ui_kv 'Update' 'CiroDownloader update'
  ui_kv 'Services' 'CiroDownloader start | stop | restart'; ui_kv 'Status' 'CiroDownloader status'; ui_kv 'Logs' 'CiroDownloader logs'
  ui_kv 'Backup' 'CiroDownloader backup'; ui_kv 'Restore' 'CiroDownloader restore /absolute/backup.tar.gz'
  ui_kv 'Settings' 'CiroDownloader settings'; ui_kv 'Cookies' 'CiroDownloader cookies'; ui_kv 'Diagnostics' 'CiroDownloader doctor | diagnose'
  ui_kv 'Large files' 'CiroDownloader large-uploads'
  ui_kv 'Telegram' 'CiroDownloader telegram'; ui_kv 'Remove' 'CiroDownloader remove'
  ui_note 'Add --plain for plain terminal output. Back returns one menu level.'
  ui_note "Project: $PROJECT_DIR"; ui_note "Backups: $BACKUP_DIR"; ui_rule
}
menu() {
  local choice
  while true; do
    render_menu; ui_prompt 'Choose [0-12]: '; read -r choice || return 0
    case "$choice" in
      1) with_lock install_bot || true ;;
      2) if with_lock update_bot; then ui_pause; exec bash "$PROJECT_DIR/manager.sh"; fi ;;
      3) with_lock delete_bot || true ;;
      4) services_menu; continue ;; 5) show_status ;;
      6) with_lock backup_bot || true ;; 7) with_lock restore_bot || true ;; 8) settings_menu; continue ;;
      9) with_lock import_youtube_cookies || true ;; 10) diagnostics_menu; continue ;;
      11) ui_plan 1; with_lock run_step 'Enable Telegram updates' enable_telegram_updates || true ;;
      12) show_help ;; 0) ui_ok 'Bye.'; return 0 ;; *) ui_warn 'Choose a number shown above.' ;;
    esac
    ui_pause
  done
}
main() {
  local action=${1:-menu}
  if [[ $action == --plain ]]; then export NO_COLOR=1 CIRO_ASCII=1; shift; action=${1:-menu}; fi
  ui_init
  cd -- "$PROJECT_DIR"
  case "$action" in --help|-h|help) show_help; return ;; --version|version) printf 'Ciro Downloader %s (manager %s)\n' "$(tr -d '\r\n' <VERSION)" "$INSTALLER_VERSION"; return ;; --preview|preview) render_menu; return ;; esac
  [[ $EUID -eq 0 ]] || { ui_error 'Run as root: sudo bash manager.sh'; return 1; }
  assert_project || return
  command -v flock >/dev/null 2>&1 || { ui_error 'The flock command is required (coreutils/util-linux).'; return 1; }
  export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a
  trap cleanup EXIT; trap interrupted INT TERM
  if [[ $action == menu ]]; then install_shortcut || return; fi
  case "$action" in
    menu) menu ;; install) with_lock install_bot ;; update) with_lock update_bot ;; remove) with_lock delete_bot ;;
    start) with_lock start_services ;; stop) with_lock stop_services ;; restart) with_lock restart_services ;;
    logs) show_logs ;; status) show_status ;; backup) with_lock backup_bot ;; restore) with_lock restore_bot "${2:-}" ;;
    settings|config) settings_menu ;; cookies) with_lock import_youtube_cookies ;; diagnose) with_lock run_diagnostics ;; doctor) doctor ;;
    large-uploads) with_lock enable_large_uploads ;;
    telegram) ui_plan 1; with_lock run_step 'Enable Telegram updates' enable_telegram_updates ;;
    *) ui_error 'Unknown command. Run CiroDownloader help.'; return 2 ;;
  esac
}
if [[ ${BASH_SOURCE[0]} == "$0" ]]; then main "$@"; fi

