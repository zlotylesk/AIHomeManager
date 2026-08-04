#!/usr/bin/env bash
# HMAI-157: preflight environment health check for AIHomeManager dev setup.
#
# Read-only. Surfaces the known signatures of a broken local environment
# (docker daemon down, container absent/exited, .env.local missing, token
# cipher keys not base64-32B) so onboarding does not become a chain of
# "why doesn't X start" guesses. Run as many times as you like.
set -u

red=$'\033[0;31m'
green=$'\033[0;32m'
yellow=$'\033[0;33m'
reset=$'\033[0m'

fail=0
warn=0

check_ok()   { echo "  ${green}OK${reset} $1"; }
check_warn() { echo "  ${yellow}!!${reset} $1"; warn=$((warn + 1)); }
check_fail() { echo "  ${red}XX${reset} $1"; fail=$((fail + 1)); }

echo "== Docker =="
if docker info >/dev/null 2>&1; then
    check_ok "daemon up"
else
    check_fail "daemon not running (start Docker Desktop / dockerd)"
fi

echo "== Containers =="
for svc in php nginx mysql redis rabbitmq messenger_worker scheduler_worker node; do
    name="aihm-${svc}-1"
    status=$(docker inspect -f '{{.State.Status}}' "$name" 2>/dev/null || echo "absent")
    if [ "$status" = "running" ]; then
        check_ok "$svc: running"
    else
        check_warn "$svc: $status"
    fi
done

echo "== Env =="
if [ -f app/.env.local ]; then
    check_ok ".env.local exists"
else
    check_fail ".env.local missing (copy from app/.env, fill secrets)"
fi

# TokenCipher (libsodium secretbox) requires exactly 32 decoded bytes per
# key. A wrong-length key surfaces as a 500 on first OAuth init request —
# usually a base64 typo, a manually pasted shorter string, or a hex-encoded
# key (64 hex chars base64-decode to 48 bytes — the HMAI-219 Trakt regression).
for key_name in DISCOGS_TOKEN_KEY GOOGLE_TOKEN_KEY TRAKT_TOKEN_KEY SPOTIFY_TOKEN_KEY; do
    val=$(grep -E "^${key_name}=" app/.env.local 2>/dev/null | head -n 1 | cut -d= -f2- | tr -d '"' | tr -d "'")
    if [ -z "$val" ]; then
        check_warn "$key_name not set"
        continue
    fi
    decoded_len=$(printf '%s' "$val" | base64 -d 2>/dev/null | wc -c | tr -d ' ')
    if [ "$decoded_len" = "32" ]; then
        check_ok "$key_name (32B decoded)"
    else
        check_fail "$key_name decoded len=$decoded_len (must be 32 — generate via 'php -r \"echo base64_encode(sodium_crypto_secretbox_keygen());\"')"
    fi
done

# The frontend firewall ships a placeholder account in the committed app/.env
# (a hash of a trivial word) purely so a fresh clone boots. Left in place, the
# UI is "protected" by a guessable password while looking secured — and every
# page carries the production API_KEY in a meta tag.
if grep -qE "^FRONTEND_PASSWORD_HASH=" app/.env.local 2>/dev/null; then
    check_ok "FRONTEND_PASSWORD_HASH overridden locally"
else
    check_warn "FRONTEND_PASSWORD_HASH not set in .env.local (falling back to the placeholder in app/.env — fine on localhost, never beyond it; generate via 'bin/console security:hash-password')"
fi

# The scheduled MySQL backup pipes mysqldump into gzip under `bash -o pipefail`,
# because POSIX sh reports only the last pipeline member's status and would mask
# a failed dump behind a successful gzip. bash is installed by docker/php/Dockerfile,
# so a missing one means the running container predates that line: the image needs
# rebuilding or the nightly backup aborts with "bash: not found". CI cannot catch
# this — it runs PHPUnit on the runner, not inside this image.
echo ""
echo "== Image =="
if docker exec aihm-php-1 sh -c 'command -v bash' >/dev/null 2>&1; then
    check_ok "php image has bash (backup pipeline can detect a failed mysqldump)"
else
    check_fail "php image lacks bash — stale image, scheduled backup will abort (run 'docker compose build php && docker compose up -d')"
fi

# Alpine's mysql-client is MariaDB's, which carries no caching_sha2_password
# module of its own — and that is MySQL 8.4's default plugin. Without the
# mariadb-connector-c package the dump cannot even log in. That failure used to
# be invisible, so the check is worth making explicit.
if docker exec aihm-php-1 sh -c 'ls /usr/lib/mariadb/plugin/caching_sha2_password.so' >/dev/null 2>&1; then
    check_ok "mysqldump can authenticate to MySQL 8.4 (caching_sha2_password present)"
else
    check_fail "mysqldump cannot authenticate to MySQL 8.4 — missing caching_sha2_password.so (rebuild the php image)"
fi

# Outcome-level checks. The two above name causes we already know about; these
# catch the whole class regardless of cause, which is the point: every nightly
# backup between 2026-06-30 and 2026-07-28 was a 20-byte empty gzip and nothing
# reported it. A real dump of this database is a few hundred KB.
#
# Both the directory and the age threshold are overridable so the failure can be
# reproduced without waiting two days or touching the real backups:
#   BACKUP_DIR=/tmp/fake bash scripts/doctor.sh
echo ""
echo "== Backups =="
backup_dir="${BACKUP_DIR:-backups}"
max_age_hours="${BACKUP_MAX_AGE_HOURS:-48}"

# Newest by the date in the FILENAME, not by mtime.
#
# The two normally agree — the job names each dump for the day it ran — but only
# the filename survives the ways mtime lies. Copying, restoring or syncing the
# backup directory stamps every file with "now", so an mtime check would call a
# months-old set perfectly fresh: a wrong answer that looks like a right one,
# which is the failure this whole check exists to remove. The filename is also
# what `make restore BACKUP=...` takes, so it is the date a human acts on.
#
# YYYY-MM-DD sorts lexicographically in date order, so plain `sort` is enough.
newest=$(ls -1 "$backup_dir"/homemanager-*.sql.gz 2>/dev/null | sort | tail -n 1)
if [ -z "$newest" ]; then
    check_warn "no backup files yet (run 'make backup-now')"
else
    size=$(wc -c < "$newest" | tr -d ' ')
    if [ "$size" -gt 1024 ]; then
        check_ok "newest backup $(basename "$newest") is ${size}B"
    else
        check_fail "newest backup $(basename "$newest") is only ${size}B — an empty dump (a 20-byte file is gzip's empty stream); restore from it would yield nothing"
    fi

    # Age. The size check above only knows whether the newest file is usable; a
    # schedule that simply stopped firing leaves a perfectly valid dump sitting
    # there and passes it. That is how 29.07-03.08 went unnoticed while doctor
    # reported OK.
    #
    # 48h rather than 24h because the backup does not actually run at its 03:00
    # cron on a workstation that is off overnight: the scheduler fires the missed
    # window whenever the host next comes up, so observed dumps land anywhere
    # from 07:00 to 22:00. A 24h threshold would cry wolf on an ordinary day, and
    # a check that is routinely wrong is one people learn to ignore.
    stamp=$(basename "$newest" .sql.gz)
    stamp=${stamp#homemanager-}
    backup_epoch=$(date -d "$stamp" +%s 2>/dev/null)
    if [ -z "$backup_epoch" ]; then
        # Never turn a date-parsing quirk into a false alarm about the backups.
        check_warn "cannot read a date from $(basename "$newest") — age not checked"
    else
        age_hours=$(( ( $(date +%s) - backup_epoch ) / 3600 ))
        if [ "$age_hours" -lt 0 ]; then
            # A dump dated in the future is either a skewed clock or a hand-named
            # file, and it would otherwise sail through the comparison below and
            # report "OK (-20h old)" — hiding a schedule that has in fact stopped,
            # which is the one thing this check exists to catch.
            check_warn "newest backup is dated ${stamp}, in the future — check the host clock; age not trusted"
        elif [ "$age_hours" -lt "$max_age_hours" ]; then
            check_ok "newest backup is from ${stamp} (${age_hours}h old, threshold ${max_age_hours}h)"
        else
            check_fail "newest backup is from ${stamp} — ${age_hours}h old, over the ${max_age_hours}h threshold; the schedule has stopped producing backups (run 'make backup-now', then check the scheduler worker)"
        fi
    fi

    # The size check deliberately looks only at the newest file, because that is
    # the one a restore reaches for first. But retention keeps 30 daily + 12
    # monthly, and an empty file among them is a day you cannot restore to — so
    # it is worth naming, as a warning rather than a failure: today's restore
    # point is intact, an older one is not.
    #
    # Sized with the same `wc -c` comparison as the check above rather than with
    # `find -size`, which rounds up to whole blocks: `-size -1k` reports nothing
    # for a 20-byte file, so the scan would have quietly found no empty backups
    # while one sat right there. Reusing the one expression is also what keeps
    # the two from drifting to different ideas of "empty".
    total_count=0
    empty_count=0
    newest_usable=""
    for f in "$backup_dir"/homemanager-*.sql.gz; do
        [ -f "$f" ] || continue
        total_count=$((total_count + 1))
        if [ "$(wc -c < "$f" | tr -d ' ')" -le 1024 ]; then
            empty_count=$((empty_count + 1))
        else
            newest_usable=$(basename "$f" .sql.gz)
            newest_usable=${newest_usable#homemanager-}
        fi
    done
    usable_count=$((total_count - empty_count))
    if [ "$empty_count" != "0" ]; then
        # The count is the point, not the file list: what an operator needs to
        # know is how many days they could actually restore to, and 26 filenames
        # bury that. When this first ran it read 26 of 27 empty, one usable point
        # left — the residue of the pre-1.31.0 dumps that authenticated to
        # nothing.
        check_warn "only ${usable_count} of ${total_count} retained backups are usable (${empty_count} are empty dumps); newest usable is from ${newest_usable:-none}"
    fi
fi

echo ""
echo "== Summary =="
if [ "$fail" = "0" ] && [ "$warn" = "0" ]; then
    echo "${green}All checks passed${reset}"
    exit 0
fi
if [ "$fail" = "0" ]; then
    echo "${yellow}${warn} warnings${reset} (non-blocking — containers may be intentionally stopped)"
    exit 0
fi
echo "${red}${fail} failures, ${warn} warnings${reset}"
exit 1
