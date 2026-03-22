#!/bin/bash
set -e

REPO="git@github.com:Klinenator/SOC2.git"
DIR="/var/www/SOC2"

echo "==> Deploying SOC2 Portal to $DIR"

if [ -d "$DIR/.git" ]; then
  echo "==> Pulling latest changes..."
  cd "$DIR" && git pull
else
  echo "==> Cloning repository..."
  sudo git clone "$REPO" "$DIR"
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
