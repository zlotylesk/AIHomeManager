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
# usually a base64 typo or a manually pasted shorter string.
for key_name in DISCOGS_TOKEN_KEY GOOGLE_TOKEN_KEY; do
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
