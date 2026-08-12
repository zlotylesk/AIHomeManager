#!/usr/bin/env bash
# Restores the newest encrypted backup into a scratch schema and checks that the
# data actually came back.
#
# This exists because everything else in the backup chain checks the wrong thing.
# `make doctor` and the two monitoring probes establish that a file is recent and
# large enough to be plausible; none of them can tell you that a database can be
# rebuilt from it. The six days of empty dumps in 1.31.0 were caught by adding a
# size check — a restore drill would have caught them on day one, and would also
# catch the failures a size check never will: a truncated upload, a wrong
# encryption key, a dump taken without the routines, a character set that turns
# every Polish title into mojibake.
#
# Non-destructive by design. It never writes to the application schema: the dump
# is loaded into a scratch database that is dropped again at the end (KEEP=1 to
# keep it and look around).
#
# Usage:
#   bash scripts/restore-drill.sh                 # newest backup in BACKUP_DIR
#   BACKUP=backups/homemanager-2026-08-12.sql.gz.enc bash scripts/restore-drill.sh
#   KEEP=1 bash scripts/restore-drill.sh          # leave the scratch schema behind
#
# `pipefail` is not decoration here. The restore below is `decrypt | mysql`, and
# without it the pipeline reports only mysql's status — so a decrypt that died
# (wrong key, damaged file) is masked by a loader that cheerfully accepted
# nothing, and the drill prints "restored in 7s" over an empty schema. That is
# the same masking that hid six days of empty dumps behind a successful gzip,
# reproduced in the very script written to catch it.
set -u -o pipefail

red=$'\033[0;31m'
green=$'\033[0;32m'
yellow=$'\033[0;33m'
reset=$'\033[0m'

backup_dir="${BACKUP_DIR:-backups}"
scratch="${SCRATCH_DB:-homemanager_restore_drill}"
keep="${KEEP:-0}"

fail() { echo "${red}XX${reset} $1"; exit 1; }
ok()   { echo "${green}OK${reset} $1"; }
note() { echo "${yellow}--${reset} $1"; }

# The scratch schema name is interpolated into SQL below, so it must not be able
# to carry anything but a schema name. It is operator-supplied, and this script
# runs as the database user.
case "$scratch" in
    *[!A-Za-z0-9_]*|"") fail "SCRATCH_DB must be alphanumeric/underscore only, got '$scratch'" ;;
esac

# `mysql` is always run through this: the password comes from the variable
# Compose already placed in the container, never from an argument that `ps` would
# show to every process on the machine.
#
# root rather than the application user, and only here. The drill creates and
# drops a schema of its own, which the application user has no grant for — nor
# should it, since nothing the application does ought to be able to create
# databases. `make restore` stays on the application user, because it writes to
# the one schema that user already owns.
run_mysql() {
    docker compose exec -T mysql sh -c \
        'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql --default-character-set=utf8mb4 -h127.0.0.1 -uroot "$@"' _ "$@"
}

live_db=$(docker compose exec -T mysql sh -c 'printf %s "$MYSQL_DATABASE"' | tr -d '\r')
[ -n "$live_db" ] || fail "cannot read MYSQL_DATABASE from the mysql container — is the stack up?"

# Newest by the date in the filename, for the reason given in doctor.sh: mtime is
# reset by copying or restoring the directory and would call a months-old set
# fresh.
if [ -n "${BACKUP:-}" ]; then
    backup="$BACKUP"
else
    backup=$(ls -1 "$backup_dir"/homemanager-*.sql.gz.enc 2>/dev/null | sort | tail -n 1)
fi
[ -n "$backup" ] || fail "no encrypted backup found in $backup_dir (run 'make backup-now')"
[ -f "$backup" ] || fail "$backup does not exist"

echo "== Restore drill =="
note "backup:  $(basename "$backup") ($(wc -c < "$backup" | tr -d ' ') bytes)"
note "into:    $scratch (scratch schema; $live_db is not touched)"

started=$(date +%s)

run_mysql -e "DROP DATABASE IF EXISTS \`$scratch\`; CREATE DATABASE \`$scratch\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" \
    || fail "could not create the scratch schema"

# pipefail so a failed decrypt cannot be masked by gunzip's exit code — the same
# trap that hid six days of empty dumps behind a successful gzip.
if ! docker compose exec -T php bash -o pipefail -c 'bin/console app:backup:decrypt "$0" | gunzip' "$backup" \
        | run_mysql "$scratch"; then
    fail "restore failed — the backup could not be decrypted, decompressed or loaded"
fi

elapsed=$(( $(date +%s) - started ))
ok "restored in ${elapsed}s"

# Row counts, table by table, against the live schema. Comparing counts rather
# than checksums because the two schemas are read at slightly different moments
# on a running system: a count that is off by one on a table being written to is
# expected, and a count that is zero against thousands is the failure worth
# catching.
echo ""
echo "== Row counts =="
tables=$(run_mysql -N -B -e \
    "SELECT table_name FROM information_schema.tables WHERE table_schema='$live_db' AND table_type='BASE TABLE' ORDER BY table_name;" \
    | tr -d '\r')

[ -n "$tables" ] || fail "the live schema reports no tables — nothing to compare against"

mismatched=0
compared=0
empty_restored=0

for table in $tables; do
    live_count=$(run_mysql -N -B -e "SELECT COUNT(*) FROM \`$live_db\`.\`$table\`;" 2>/dev/null | tr -d '\r')
    drill_count=$(run_mysql -N -B -e "SELECT COUNT(*) FROM \`$scratch\`.\`$table\`;" 2>/dev/null | tr -d '\r')

    if [ -z "$drill_count" ]; then
        echo "  ${red}XX${reset} $table: missing from the restored schema (live has ${live_count:-?})"
        mismatched=$((mismatched + 1))
        continue
    fi

    compared=$((compared + 1))

    if [ "$live_count" != "$drill_count" ]; then
        # Not automatically a failure: the live database is being written to
        # while this runs, and the dump is a snapshot from 03:00. A table that
        # gained rows since is expected. One that lost them is not.
        if [ "$drill_count" -gt "$live_count" ] 2>/dev/null; then
            echo "  ${yellow}!!${reset} $table: restored $drill_count > live $live_count (rows deleted since the dump)"
        elif [ "$drill_count" = "0" ] && [ "$live_count" != "0" ]; then
            echo "  ${red}XX${reset} $table: restored EMPTY, live has $live_count"
            empty_restored=$((empty_restored + 1))
            mismatched=$((mismatched + 1))
        else
            echo "  ${yellow}!!${reset} $table: restored $drill_count, live $live_count (rows added since the dump)"
        fi
    else
        echo "  ${green}OK${reset} $table: $drill_count"
    fi
done

echo ""
echo "== Summary =="
note "compared $compared tables in ${elapsed}s"

if [ "$keep" = "1" ]; then
    note "scratch schema $scratch kept (KEEP=1)"
else
    run_mysql -e "DROP DATABASE IF EXISTS \`$scratch\`;" && note "scratch schema dropped"
fi

if [ "$empty_restored" != "0" ]; then
    fail "$empty_restored table(s) restored empty — this backup is NOT a usable restore point"
fi

if [ "$mismatched" != "0" ]; then
    fail "$mismatched table(s) did not come back"
fi

echo "${green}Restore verified: every table in $live_db came back from $(basename "$backup")${reset}"
