# Portal security and deployment

The portal contains restricted SOC 2 evidence and must not be deployed without HTTPS and authentication.

Before deployment:

1. Issue a certificate whose subject alternative names include `soc2.rrsaccess.com` and place it under `/etc/letsencrypt/live/soc2.rrsaccess.com/`.
2. Configure Google OAuth using the same pattern as WinPatchAgent. Create `/etc/soc2/soc2-secrets.conf`, owned by `root:www-data` with mode `640`, containing:

   `fastcgi_param SOC2_GOOGLE_CLIENT_ID "...";`

   `fastcgi_param SOC2_GOOGLE_CLIENT_SECRET "...";`

   `fastcgi_param SOC2_GOOGLE_REDIRECT_URI "https://soc2.rrsaccess.com/api/auth.php?action=callback";`

   `fastcgi_param SOC2_GOOGLE_HOSTED_DOMAIN "accessrrs.com";`

   `fastcgi_param SOC2_ALLOWED_EMAILS "authorized-user-1@accessrrs.com,authorized-user-2@accessrrs.com";`

   Add the redirect URI to the Google OAuth client. When `SOC2_ALLOWED_EMAILS` is populated, both the Workspace domain and explicit email allow-list must match.
3. Keep `data/smtp.json`, `data/evidence.json`, `data/change_population.json`, and uploaded evidence out of Git and include them in encrypted backups.
4. Run the ticket export as a read-only database user:

   `php scripts/export_change_population.php --env="$HOME/.env" --since="YYYY-MM-DD"`

The API accepts state-changing requests only when the same-origin client sends `X-SOC2-Request: 1`. Nginx denies direct access to runtime data, uploads, scripts, and repository metadata.
