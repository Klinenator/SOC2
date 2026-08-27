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

# The convention, stated once and used everywhere below.
#
# These are NOT "$USER". This script previously chowned the tree to "$USER":"$USER" before
# pulling, which is correct when a human runs it from an interactive shell as ubuntu, and
# catastrophic when it runs as root -- SSM, cron, a sudo invocation -- because then it means
# root:root and php-fpm can no longer read a single file. On 2026-08-27 that took the portal
# down: the tree went root:root, `set -e` then aborted on a dirty `git pull`, and the
# permission restoration at the bottom never ran.
OWNER=ubuntu
GROUP=www-data

# Restore permissions no matter how we leave, including a failed pull or nginx -t. The window
# between chown and restore is exactly where the outage above lived.
restore_permissions() {
  echo "==> Restoring permissions..."
  sudo chown -R "$OWNER":"$GROUP" "$DIR"
  sudo find "$DIR" -path "$DIR/.git" -prune -o -type d -print0 | sudo xargs -0 -r chmod 0750
  sudo find "$DIR" -path "$DIR/.git" -prune -o -type f -print0 | sudo xargs -0 -r chmod 0640
  # Executables keep their bit. A blanket 0640 strips +x from deploy.sh and .githooks/*, which
  # git sees as a mode change -- so the tree is dirty and the NEXT pull refuses to run. This
  # script used to make itself non-executable every time it ran.
  sudo find "$DIR" -path "$DIR/.git" -prune -o -type f -name '*.sh' -print0 | sudo xargs -0 -r chmod 0750
  [ -d "$DIR/.githooks" ] && sudo chmod 0750 "$DIR"/.githooks/* 2>/dev/null || true
  sudo chown -R "$OWNER":"$OWNER" "$DIR/.git"

  # Runtime data and uploads are WRITTEN by the app, so they belong to www-data outright.
  sudo chown -R www-data:www-data "$DIR/data" "$DIR/uploads"
  sudo chmod 0770 "$DIR/data" "$DIR/uploads"
  sudo find "$DIR/data" "$DIR/uploads" -type f -print0 | sudo xargs -0 -r chmod 0640
  # Rewritten in place by the API, so these need group write.
  for f in tasks.json patch_jobs.json evidence.json controls.json audit_tests.json; do
    [ -e "$DIR/data/$f" ] && sudo chmod 0660 "$DIR/data/$f"
  done
  return 0
}
trap restore_permissions EXIT

echo "==> Fixing repository ownership..."
sudo chown -R "$OWNER":"$OWNER" "$DIR" 2>/dev/null || true
git config --global --add safe.directory "$DIR" 2>/dev/null || true

if [ -d "$DIR/.git" ]; then
  echo "==> Pulling latest changes..."
  cd "$DIR" && git pull
else
  echo "==> Cloning repository..."
  sudo git clone "$REPO" "$DIR"
  sudo chown -R "$USER":"$USER" "$DIR"
fi

# Permissions are handled by restore_permissions() via the EXIT trap set above, so they are
# applied whether this script finishes or dies partway. `git pull` writes the files it touches
# with the pulling user's ownership and umask, so restoring them on every deploy is not
# optional: on 2026-08-27 an un-restored pull left api/auth.php unreadable by php-fpm, the
# auth_request subrequest returned 500, and every page went with it.
#
#   Failed opening required '/var/www/SOC2/api/auth.php'
#   auth request unexpected status: 500

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
