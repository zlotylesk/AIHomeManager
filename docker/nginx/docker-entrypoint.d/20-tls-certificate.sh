#!/bin/sh
# Puts a usable certificate at the two paths default.prod.conf names, so nginx
# always starts.
#
# That guarantee is what makes the whole HTTPS story bootstrappable. nginx
# refuses to start when ssl_certificate points at a file that is not there, and
# the only way to OBTAIN the real certificate is an http-01 challenge served by
# a running nginx. A fresh host would otherwise deadlock: no certificate, so no
# nginx; no nginx, so no certificate.
#
# The nginx image runs every executable script in /docker-entrypoint.d/ before
# starting the server, which is why this is a script here rather than a step in
# a deployment procedure someone has to remember.

set -e

CERT_DIR=/etc/nginx/certs
LIVE=/etc/letsencrypt/live/aihm

mkdir -p "$CERT_DIR"

if [ -f "$LIVE/fullchain.pem" ] && [ -f "$LIVE/privkey.pem" ]; then
    ln -sf "$LIVE/fullchain.pem" "$CERT_DIR/fullchain.pem"
    ln -sf "$LIVE/privkey.pem" "$CERT_DIR/privkey.pem"
    echo "$0: serving the Let's Encrypt certificate from $LIVE."
    exit 0
fi

# A symlink left over from a previous start whose lineage is now gone — the
# volume was dropped, or the certificates were deleted to force a re-issue.
# nginx treats a dangling symlink exactly like a missing file and refuses to
# start, so it is cleared before the placeholder is written.
if [ -L "$CERT_DIR/fullchain.pem" ] || [ -L "$CERT_DIR/privkey.pem" ]; then
    rm -f "$CERT_DIR/fullchain.pem" "$CERT_DIR/privkey.pem"
fi

if [ -f "$CERT_DIR/fullchain.pem" ] && [ -f "$CERT_DIR/privkey.pem" ]; then
    exit 0
fi

# Generated at container start, never at build time: an image that carried a
# private key would ship the same one to every instance and keep it in a layer.
openssl req -x509 -nodes -newkey rsa:2048 -days 3650 \
    -keyout "$CERT_DIR/privkey.pem" \
    -out "$CERT_DIR/fullchain.pem" \
    -subj "/CN=aihm.invalid" >/dev/null 2>&1

echo "$0: no Let's Encrypt certificate found — starting on a SELF-SIGNED placeholder."
echo "$0: browsers will warn until 'make prod-cert-init DOMAIN=... EMAIL=...' has run."
