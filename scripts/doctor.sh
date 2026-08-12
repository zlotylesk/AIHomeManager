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
#
# BACKUP_ENCRYPTION_KEY sits in the same list and is checked the same way, but it
# fails differently and worse. The token keys can be regenerated — re-authorise
# with the provider and carry on. This one cannot: it is the only thing that
# turns the stored dumps back into a database, so losing it loses every backup at
# once, retroactively, including the copies that made it off the host. Keep it
# somewhere that is neither the backup directory nor a copy of it.
for key_name in DISCOGS_TOKEN_KEY GOOGLE_TOKEN_KEY TRAKT_TOKEN_KEY SPOTIFY_TOKEN_KEY BACKUP_ENCRYPTION_KEY; do
    val=$(grep -E "^${key_name}=" app/.env.local 2>/dev/null | tail -n 1 | cut -d= -f2- | tr -d '"' | tr -d "'")
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

# Compose reads only `.env` from the project directory -- never `.env.local` --
# and that file is tracked. Production therefore does not edit it: `make prod-*`
# layers a gitignored `.env.local` beside it, read second, and the second wins.
#
# The layering fails quietly in one direction, which is what this section is
# for. A variable MISSING from the overlay stops nothing; it falls through to
# the tracked development value and the stack comes up on a password that is
# published in a public repository. Every container starts, every health probe
# is green, and the credential is one `git clone` away from anybody.
#
# Compared against whatever `.env` currently holds rather than against a copy of
# those strings kept here, so rotating a development value cannot leave this
# check measuring against a string nobody uses any more.
#
# COMPOSE_ENV_DIR moves both reads, so the verdicts can be exercised without a
# production host:
#   COMPOSE_ENV_DIR=/tmp/fake-host bash scripts/doctor.sh
echo ""
echo "== Production secrets =="
compose_env_dir="${COMPOSE_ENV_DIR:-.}"

# MYSQL_USER, MYSQL_DATABASE and RABBITMQ_USER are deliberately not here: they
# are names, not secrets, and a production instance may keep them as they are.
prod_secrets="MYSQL_ROOT_PASSWORD MYSQL_PASSWORD REDIS_PASSWORD RABBITMQ_PASSWORD GRAYLOG_PASSWORD_SECRET GRAYLOG_ROOT_PASSWORD_SHA2"

# `tail -n 1`, not `head`: a duplicated key in a dotenv file resolves to the LAST
# definition, which is what Compose interpolates -- verified against
# `docker compose config`. Reading the first would answer about a line the stack
# does not use, and the way that happens is an operator appending a corrected
# password to the end of the file rather than editing it in place.
#
# The trailing `tr -d '\r'` is not decoration either. The repository is developed
# on Windows, where a checkout writes `.env` with CRLF while an overlay created
# on the production host has LF -- without stripping it, an identical password
# compares as different and the check reports an override that never happened.
env_value() {
    grep -E "^${2}=" "$1" 2>/dev/null | tail -n 1 | cut -d= -f2- | tr -d '"' | tr -d "'" | tr -d '\r'
}

# Severity follows whether this host actually deploys, which is the difference
# between a finding and an emergency. `make prod-*` runs the stack under its own
# Compose project, so its containers are the one honest signal available here: a
# root `.env.local` is not one, because a workstation has every reason to keep
# one (the GitHub MCP token lives there, and the tracked `.env` says to put it
# there rather than beside it).
#
# Getting this wrong in the safe-looking direction would cost the whole check.
# A workstation that went red here on every run would teach its owner to skip
# `make doctor`, and the checks it would then stop reading are the backup ones.
#
# Off a production host the same findings collapse to a single warning rather
# than one line per variable: they are true there too, and also entirely
# expected, so spending six lines on them buries the checks that are neither.
# The wording of each finding is identical either way — only the severity and
# the grouping move — so the CI step that pins both verdicts reads the same
# strings a production host would.
if docker ps -a --filter 'label=com.docker.compose.project=aihm-prod' -q 2>/dev/null | grep -q .; then
    deploys_here=1
else
    deploys_here=0
fi

if [ ! -f "$compose_env_dir/.env" ]; then
    check_fail "$compose_env_dir/.env is missing — Compose has nothing to interpolate and no service can start"
elif [ ! -f "$compose_env_dir/.env.local" ]; then
    if [ "$deploys_here" = "1" ]; then
        check_fail "no $compose_env_dir/.env.local — every 'make prod-*' target on this host runs on the development passwords in the tracked .env (docs/configuration.md, 'Infrastructure credentials')"
    else
        check_ok "no production overlay, and none needed — the development stack runs on the tracked .env"
    fi
else
    # The failure the overlay exists to prevent, having already happened. Worth
    # its own check because the file looks entirely normal on disk either way,
    # and `git status` stops mentioning it the moment it is committed.
    if git ls-files --error-unmatch -- "$compose_env_dir/.env.local" >/dev/null 2>&1; then
        check_fail ".env.local is TRACKED by git — the production secrets are in the repository history; untrack it ('git rm --cached .env.local') and rotate every value it holds"
    else
        check_ok ".env.local present and untracked"
    fi

    on_dev_value=""
    for name in $prod_secrets; do
        tracked_val=$(env_value "$compose_env_dir/.env" "$name")
        local_val=$(env_value "$compose_env_dir/.env.local" "$name")

        if [ -z "$local_val" ]; then
            reason="not set in .env.local — it falls through to the development value in .env"
        elif [ "$local_val" = "$tracked_val" ]; then
            reason="the development value from .env, copied verbatim"
        else
            continue
        fi

        on_dev_value="$on_dev_value $name"
        if [ "$deploys_here" = "1" ]; then
            check_fail "$name is $reason"
        fi
    done

    if [ -z "$on_dev_value" ]; then
        check_ok "all $(echo $prod_secrets | wc -w | tr -d ' ') production secrets overridden with values of their own"
    elif [ "$deploys_here" != "1" ]; then
        check_warn "still on the development values from .env:$on_dev_value — expected on a workstation, and exactly what 'make prod-*' would deploy with (docs/configuration.md, 'Infrastructure credentials')"
    fi
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
# The same two variables App\Monitoring\Probe\BackupFreshnessProbe reads, so the
# nightly alert and this command cannot disagree about whether a backup is fresh.
max_age_hours="${BACKUP_MAX_AGE_HOURS:-48}"
min_bytes="${BACKUP_MIN_BYTES:-1024}"

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
newest=$(ls -1 "$backup_dir"/homemanager-*.sql.gz.enc 2>/dev/null | sort | tail -n 1)
if [ -z "$newest" ]; then
    check_warn "no backup files yet (run 'make backup-now')"
else
    size=$(wc -c < "$newest" | tr -d ' ')
    if [ "$size" -ge "$min_bytes" ]; then
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
    stamp=$(basename "$newest" .sql.gz.enc)
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
    for f in "$backup_dir"/homemanager-*.sql.gz.enc; do
        [ -f "$f" ] || continue
        total_count=$((total_count + 1))
        if [ "$(wc -c < "$f" | tr -d ' ')" -le 1024 ]; then
            empty_count=$((empty_count + 1))
        else
            newest_usable=$(basename "$f" .sql.gz.enc)
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

# HealthChecker's own disk probes report `degraded` at 80% and only go as far
# as `down` (503, disk_database only) at 95% — a threshold this check reads
# BEFORE, not instead of. By the time /api/health is returning 503 the
# instance is already refusing traffic over its own logs or dumps; this is
# the warning meant to arrive while there is still time to prune something.
#
# The two paths are the container-internal mount targets from
# docker-compose.yml (the read-only mysql_data mount and the backups bind
# mount) — the same defaults HealthChecker's own DATABASE_DATA_DIR/BACKUP_DIR
# env vars resolve to. They are read inside the php container rather than on
# the host, because `df` on the host would answer about a different
# filesystem entirely.
#
# Deliberately NOT read from a $BACKUP_DIR shell override: the backup
# freshness check below already gives that name a different meaning — the
# HOST-side directory holding the dump files, for exercising the check
# without waiting on the real schedule. Reusing it here would point this
# check at whatever fixture directory a caller passed for that purpose
# instead of the real container mount.
echo ""
echo "== Disk =="
disk_warn_percent="${DISK_WARN_PERCENT:-80}"
if ! docker inspect -f '{{.State.Status}}' aihm-php-1 2>/dev/null | grep -q running; then
    check_warn "php container not running — disk usage not checked"
else
    for label_path in "database:/var/lib/mysql" "backups:/backups"; do
        label=${label_path%%:*}
        path=${label_path#*:}

        used=$(docker exec aihm-php-1 sh -c "df -P '$path' 2>/dev/null | tail -n 1 | awk '{print \$5}' | tr -d '%'")
        if [ -z "$used" ]; then
            check_warn "$label ($path): could not read disk usage — mount missing?"
        elif [ "$used" -ge "$disk_warn_percent" ]; then
            check_warn "$label ($path) is ${used}% full — at or above the ${disk_warn_percent}% warning threshold (HealthChecker degrades at 80%, and disk_database goes down at 95%)"
        else
            check_ok "$label ($path): ${used}% full"
        fi
    done
fi

# The local checks above all answer "is there a good backup on this machine",
# which stays true right up until the machine is what is lost. This one asks the
# question that survives that.
#
# Delegated to the application rather than checked in shell, and for a reason
# that is not tidiness: BACKUP_REMOTE_DIR is a path INSIDE the container, and an
# rclone remote is reachable only with credentials in the container's own config.
# A host-side `ls` would be inspecting a different filesystem entirely and would
# cheerfully report on nothing. `app:backup:offsite-status` reads the same
# destination object the nightly job pushes to and BackupOffsiteProbe watches, so
# all three cannot disagree.
echo ""
echo "== Off-host backups =="
if ! docker inspect -f '{{.State.Status}}' aihm-php-1 2>/dev/null | grep -q running; then
    check_warn "php container not running — off-host backup state not checked"
else
    offsite=$(docker exec aihm-php-1 sh -c 'cd /var/www/html && bin/console app:backup:offsite-status 2>/dev/null' | tr -d '\r')
    case "$offsite" in
        backend=none*)
            # A warning, not a failure, and for the same reason MAILER_DSN is: a
            # laptop that keeps its backups locally is a correctly configured
            # laptop. It still has to be said out loud, because "we have off-host
            # backups" is exactly the kind of thing an instance is assumed to be
            # doing until the day it is needed.
            check_warn "no off-host copy configured (BACKUP_REMOTE_BACKEND=none) — every backup exists only on this machine"
            ;;
        *state=unreachable*)
            check_fail "off-host destination cannot be read: ${offsite#* } (mount dropped, or the remote's credentials changed)"
            ;;
        *state=empty*)
            check_fail "off-host destination is reachable but holds no backup — run 'make backup-now' and read what it says about the copy"
            ;;
        *state=stale*)
            check_fail "off-host copy has stopped arriving: ${offsite#*state=stale }"
            ;;
        *state=ok*)
            check_ok "off-host copy current: ${offsite#*state=ok }"
            ;;
        "")
            check_warn "could not read off-host backup state (is the container's console working?)"
            ;;
        *)
            check_warn "unexpected off-host backup state: $offsite"
            ;;
    esac

    # rclone is installed by docker/php/Dockerfile. A container that predates
    # that line selects the backend happily and then fails every night at push
    # time — the same invisible, image-level gap as bash and the MySQL auth
    # plugin, which CI cannot see because it never runs inside this image.
    case "$offsite" in
        backend=rclone*)
            if docker exec aihm-php-1 sh -c 'command -v rclone' >/dev/null 2>&1; then
                check_ok "php image has rclone"
            else
                check_fail "BACKUP_REMOTE_BACKEND=rclone but the php image has no rclone — stale image (run 'docker compose build php && docker compose up -d')"
            fi
            ;;
    esac
fi

# A message in the dead-letter queue is a failure that has already happened and
# is waiting for a human — a Trakt import, a notification, an unindexed
# document. Nothing else surfaces it: the DLQ has no consumer by design, so it
# stays silent however deep it gets.
#
# This is a warning rather than a failure. The environment is healthy; it is the
# work that is not. Failing here would make `make doctor` red for a reason that
# has nothing to do with the setup it exists to check, and a check that is red
# for unrelated reasons stops being read.
echo ""
echo "== Queues =="
if ! docker inspect -f '{{.State.Status}}' aihm-rabbitmq-1 2>/dev/null | grep -q running; then
    check_warn "rabbitmq not running — DLQ depth not checked"
else
    dlq=$(docker exec aihm-rabbitmq-1 rabbitmqctl list_queues -q --no-table-headers name messages 2>/dev/null \
        | awk '$1 == "series_events_failed" { print $2 }')
    if [ -z "$dlq" ]; then
        # Absent, not empty. The transport declares it lazily, so before anything
        # has ever failed there is genuinely no queue — which is fine, and worth
        # distinguishing from "zero messages" so it does not read as a probe that
        # silently found nothing.
        check_ok "no dead-letter queue yet (nothing has failed)"
    elif [ "$dlq" = "0" ]; then
        check_ok "dead-letter queue is empty"
    else
        check_warn "${dlq} message(s) parked in the dead-letter queue — a failed job is waiting for you (inspect: 'docker compose exec php bin/console messenger:stats'; retry: '… messenger:failed:retry')"
    fi
fi

# Everything above detects. This one asks whether detection can reach anybody.
#
# The monitoring sweep is only as useful as the mailbox at the end of it, and the
# committed app/.env ships MAILER_DSN=null://null — a transport that accepts
# every message and sends none, reporting success as it does so. An instance left
# on that default has working probes, a correct alert state file, and no
# alerting at all: exactly the shape of failure the alerting exists to end, one
# level up.
#
# Warnings, not failures, and for the reason the DLQ check gives: a laptop that
# does not e-mail itself is a correctly configured laptop, and a check that is
# red on every dev box stops being read. It still has to be said out loud, since
# nothing else ever will.
echo ""
echo "== Alerting =="
mailer_dsn=$(grep -E "^MAILER_DSN=" app/.env.local 2>/dev/null | tail -n 1 | cut -d= -f2- | tr -d '"' | tr -d "'")
case "$mailer_dsn" in
    "")
        check_warn "MAILER_DSN not set in .env.local — falling back to null://null in app/.env, which accepts every alert and delivers none; monitoring runs but nobody is told (docs/operations.md, 'Failure alerting')"
        ;;
    null://*)
        check_warn "MAILER_DSN is ${mailer_dsn} — the null transport accepts every alert and delivers none; monitoring runs but nobody is told"
        ;;
    *)
        check_ok "MAILER_DSN configured (operational alerts have somewhere to go)"
        ;;
esac

# A real transport pointed at the placeholder inbox is the same outcome by a
# different route, so it is worth its own line rather than being folded above.
mail_to=$(grep -E "^NOTIFICATIONS_MAIL_TO=" app/.env.local 2>/dev/null | tail -n 1 | cut -d= -f2- | tr -d '"' | tr -d "'")
case "$mail_to" in
    ""|owner@localhost)
        check_warn "NOTIFICATIONS_MAIL_TO is the placeholder (owner@localhost) — alerts would be addressed to a mailbox nobody reads"
        ;;
    *)
        check_ok "NOTIFICATIONS_MAIL_TO set to a real address"
        ;;
esac

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
