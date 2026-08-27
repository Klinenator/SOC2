#!/bin/bash
set -e

REPO="git@github.com:Klinenator/SOC2.git"
DIR="/var/www/SOC2"

echo "==> Deploying SOC2 Portal to $DIR"

CERT_DIR="/etc/letsencrypt/live/soc2.rrsaccess.com"

# Test the certificate AS ROOT, which is who reads it.
#
# This used to be a plain `[ ! -r ... ]`, run as the user invoking the script. /etc/letsencrypt
# /live and /archive are 0700 root:root, so the test failed even when the certificate was
# present and perfectly usable by nginx — the script was asking whether the wrong account
# could read it. On 2026-08-26 it reported the certificate missing when it genuinely was; the
# same message would have appeared once it existed, sending the next person looking for a
# certificate problem that was not there.
if ! sudo test -r "$CERT_DIR/fullchain.pem" || ! sudo test -r "$CERT_DIR/privkey.pem"; then
  echo "ERROR: TLS certificate for soc2.rrsaccess.com not found in $CERT_DIR" >&2
  echo "       Issue it with: sudo certbot certonly --dns-route53 -d soc2.rrsaccess.com" >&2
  exit 1
fi

# No /etc/soc2/soc2-secrets.conf check any more, and no such file.
#
# It existed to inject the Google OAuth client into PHP as fastcgi_params — a second copy of
# credentials this host already holds as $clientID / $clientSecret in /var/lib/php/config.php.
# It had never been created, so nginx.conf could not load, so the portal ran with no
# auth_request at all and served the whole audit package to the internet unauthenticated.
# api/auth.php now reads the host config directly; an explicit fastcgi_param still overrides.
if ! sudo test -r /var/lib/php/config.php && ! sudo test -r /var/lib/php-fpm/config.php; then
  echo "WARNING: no /var/lib/php{,-fpm}/config.php found — auth.php will find no OAuth" >&2
  echo "         client and the portal will answer 503 on login until one is present." >&2
fi

echo "==> Fixing repository ownership..."
sudo chown -R "$USER":"$USER" "$DIR" 2>/dev/null || true
git config --global --add safe.directory "$DIR" 2>/dev/null || true

if [ -d "$DIR/.git" ]; then
  echo "==> Pulling latest changes..."
  cd "$DIR" && git pull
else
  echo "==> Cloning repository..."
  sudo git clone "$REPO" "$DIR"
  sudo chown -R "$USER":"$USER" "$DIR"
fi

echo "==> Setting permissions..."
sudo chown -R www-data:www-data "$DIR/data" "$DIR/uploads"
sudo chmod 775 "$DIR/data" "$DIR/uploads"

echo "==> Installing nginx config..."
sudo cp "$DIR/nginx.conf" /etc/nginx/sites-available/soc2
sudo ln -sf /etc/nginx/sites-available/soc2 /etc/nginx/sites-enabled/soc2

echo "==> Disabling default nginx site..."
sudo rm -f /etc/nginx/sites-enabled/default

echo "==> Testing nginx config..."
sudo nginx -t

echo "==> Reloading nginx..."
sudo systemctl reload nginx

echo "==> Done. SOC2 Portal is live."
