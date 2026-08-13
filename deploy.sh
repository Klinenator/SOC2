#!/bin/bash
set -e

REPO="git@github.com:Klinenator/SOC2.git"
DIR="/var/www/SOC2"

echo "==> Deploying SOC2 Portal to $DIR"

CERT_DIR="/etc/letsencrypt/live/soc2.rrsaccess.com"
AUTH_FILE="/etc/soc2/soc2-secrets.conf"
if [ ! -r "$CERT_DIR/fullchain.pem" ] || [ ! -r "$CERT_DIR/privkey.pem" ]; then
  echo "ERROR: Valid TLS certificate files for soc2.rrsaccess.com are required in $CERT_DIR" >&2
  exit 1
fi
if [ ! -r "$AUTH_FILE" ]; then
  echo "ERROR: $AUTH_FILE is required. Copy SECURITY.md's Google OAuth fastcgi parameters into it." >&2
  exit 1
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
