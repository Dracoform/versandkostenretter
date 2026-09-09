#!/usr/bin/env bash
#
# cron-import-shop.sh — production cron wrapper for bin/import-shop.php.
#
# Purpose: Plesk scheduled tasks call this wrapper (twice daily). It runs the
# PHP importer for ONE shop, writes a dedicated log file per run, detects
# problems (non-zero exit, skipped membership enrichment, enrichment
# warnings) and sends a monitoring mail via curl/SMTP ONLY on problems.
# Successful runs produce no mail.
#
# No locking: this host has no flock; with two daily runs and short import
# durations the residual overlap risk is explicitly accepted.
#
# Credentials: SMTP credentials are read by curl from the .netrc file
# (path configured below, exists OUTSIDE the repository). This wrapper never
# reads, prints or copies that file — it only passes its path to curl.
#
# Usage:
#   bin/cron-import-shop.sh <shop-handle>
#
# Exit codes:
#   0  import fully successful (no mail sent)
#   1  usage error (missing/invalid shop handle)
#   2  import failed (exit != 0)          [mail sent]
#   3  membership enrichment skipped      [mail sent]
#   4  enrichment warnings present        [mail sent]
#   5  monitoring mail delivery failed    [mail content is in the run log]
#
# Configuration via environment (defaults shown):
#   CRON_IMPORT_PHP        PHP binary       (default /usr/local/php84/bin/php)
#   CRON_IMPORT_LOG_DIR    log directory    (default <repo>/storage/import-logs)
#   CRON_IMPORT_LOG_DAYS   retention days   (default 30)
#   CRON_IMPORT_NETRC      netrc path       (default ~/.config/versandkostenretter/.netrc)
#   CRON_IMPORT_SMTP       SMTP URL         (default smtp://mx2f6e.netcup.net:587)
#   CRON_IMPORT_MAIL_FROM  sender address   (default monitoring@versandkostenretter.de)
#   CRON_IMPORT_MAIL_TO    recipient        (default monitoring@versandkostenretter.de)
#
# The shop handle is validated against ^[a-z0-9-]{1,64}$ before it is used in
# any filename or command.

set -u

# ---------------------------------------------------------------- constants
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(dirname "$SCRIPT_DIR")"

PHP_BIN="${CRON_IMPORT_PHP:-/usr/local/php84/bin/php}"
IMPORTER="$REPO_DIR/bin/import-shop.php"
LOG_DIR="${CRON_IMPORT_LOG_DIR:-$REPO_DIR/storage/import-logs}"
LOG_DAYS="${CRON_IMPORT_LOG_DAYS:-30}"
NETRC_FILE="${CRON_IMPORT_NETRC:-$HOME/.config/versandkostenretter/.netrc}"
SMTP_URL="${CRON_IMPORT_SMTP:-smtp://mx2f6e.netcup.net:587}"
MAIL_FROM="${CRON_IMPORT_MAIL_FROM:-monitoring@versandkostenretter.de}"
MAIL_TO="${CRON_IMPORT_MAIL_TO:-monitoring@versandkostenretter.de}"
CURL_BIN="${CRON_IMPORT_CURL:-/usr/bin/curl}"

# ---------------------------------------------------------------- arguments
if [ "$#" -ne 1 ]; then
    echo "Usage: $0 <shop-handle>" >&2
    exit 1
fi
SHOP="$1"

# Validate the handle BEFORE using it in filenames/commands.
if ! printf '%s' "$SHOP" | grep -Eq '^[a-z0-9-]{1,64}$'; then
    echo "Error: invalid shop handle '$SHOP' (allowed: a-z 0-9 '-', max 64 chars)" >&2
    exit 1
fi

# ---------------------------------------------------------------- run import
STAMP="$(date +%Y%m%d-%H%M%S)"
LOG_FILE="$LOG_DIR/$SHOP-$STAMP-$$.log"

mkdir -p -- "$LOG_DIR" 2>/dev/null || true
chmod 700 "$LOG_DIR" 2>/dev/null || true

# stdout + stderr of THIS import run into ONE dedicated log file.
( cd "$REPO_DIR" && "$PHP_BIN" "$IMPORTER" "$SHOP" ) >"$LOG_FILE" 2>&1
IMPORT_EXIT=$?

TIMESTAMP="$(date '+%Y-%m-%d %H:%M:%S %Z')"

# ---------------------------------------------------------------- evaluate
# Detect the importer's explicit signals (no invented heuristics):
#   - non-zero exit code           -> import problem
#   - "Membership sync: SKIPPED"   -> fail-closed enrichment, data NOT updated
#   - "Enrichment warnings: N" with N > 0 -> enrichment warnings present
REASON=""
MAIL_EXIT=1

if [ "$IMPORT_EXIT" -ne 0 ]; then
    REASON="import exited with code $IMPORT_EXIT"
    MAIL_EXIT=2
else
    if grep -q '^Membership sync: SKIPPED' "$LOG_FILE"; then
        REASON="membership enrichment skipped (fail-closed, existing memberships kept)"
        MAIL_EXIT=3
    elif grep -Eq '^Enrichment warnings: [1-9][0-9]*' "$LOG_FILE"; then
        REASON="membership enrichment completed with warnings"
        MAIL_EXIT=4
    fi
fi

if [ -z "$REASON" ]; then
    # Fully successful run: no mail, normal exit. Retention still runs.
    find "$LOG_DIR" -maxdepth 1 -type f \
        -name "$SHOP-*.log" -mtime +"$LOG_DAYS" -delete 2>/dev/null || true
    exit 0
fi

# ---------------------------------------------------------------- send mail
MAIL_FILE="$(mktemp /tmp/vskr-mail-XXXXXX.eml)"
chmod 600 "$MAIL_FILE"

{
    echo "From: ${MAIL_FROM}"
    echo "To: ${MAIL_TO}"
    echo "Subject: [VSKR] Importproblem: ${SHOP}"
    echo "Date: $(date -R)"
    echo "MIME-Version: 1.0"
    echo "Content-Type: text/plain; charset=utf-8"
    echo "Content-Transfer-Encoding: 8bit"
    echo ""
    echo "Shop:        ${SHOP}"
    echo "Zeitpunkt:   ${TIMESTAMP}"
    echo "Exit-Code:   ${IMPORT_EXIT}"
    echo "Grund:       ${REASON}"
    echo "Logdatei:    ${LOG_FILE}"
    echo ""
    echo "----- Vollstaendige Ausgabe dieses Importlaufs -----"
    cat -- "$LOG_FILE"
} >"$MAIL_FILE"

CURL_LOG="$LOG_FILE.mail-error"
MAIL_OK=0
if [ -f "$NETRC_FILE" ]; then
    "$CURL_BIN" --silent --show-error --ssl-reqd \
        --netrc-file "$NETRC_FILE" \
        --mail-from "$MAIL_FROM" \
        --mail-rcpt "$MAIL_TO" \
        --upload-file "$MAIL_FILE" \
        "$SMTP_URL" 2>"$CURL_LOG" && MAIL_OK=1
else
    echo "$(date '+%Y-%m-%d %H:%M:%S') netrc file not found: $NETRC_FILE" >"$CURL_LOG"
fi

if [ "$MAIL_OK" -eq 1 ]; then
    rm -f -- "$CURL_LOG"
else
    {
        echo "$(date '+%Y-%m-%d %H:%M:%S') MONITORING MAIL FAILED (curl exit $?)"
        echo "SMTP: $SMTP_URL"
        cat -- "$CURL_LOG" 2>/dev/null
    } >>"$LOG_FILE"
    rm -f -- "$CURL_LOG" "$MAIL_FILE"
    echo "Error: import of '$SHOP' had problems ($REASON) AND the monitoring mail could not be sent (see $LOG_FILE)" >&2
    exit 5
fi

rm -f -- "$MAIL_FILE"

# ---------------------------------------------------------------- retention
# Delete only THIS shop's old import logs; never touch foreign files.
find "$LOG_DIR" -maxdepth 1 -type f \
    -name "$SHOP-*.log" -mtime +"$LOG_DAYS" -delete 2>/dev/null || true

exit "$MAIL_EXIT"
