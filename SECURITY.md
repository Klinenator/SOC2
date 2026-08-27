# Portal security and deployment

The portal contains restricted SOC 2 evidence and must not be deployed without HTTPS and authentication.

Before deployment:

1. Issue a certificate whose subject alternative names include `soc2.rrsaccess.com` and place it under `/etc/letsencrypt/live/soc2.rrsaccess.com/`.
2. **Google OAuth needs no configuration file on this host.** `api/auth.php` reads
   `$clientID` and `$clientSecret` from the host's own `/var/lib/php/config.php`
   (`/var/lib/php-fpm/config.php` is also accepted), which is `0640 root:www-data` and
   readable by php-fpm. The redirect URI is derived from the request host and the hosted
   domain defaults to `accessrrs.com`, so a working deployment needs nothing beyond the
   credentials already on the box. Confirm the Google OAuth client lists
   `https://soc2.rrsaccess.com/api/auth.php?action=callback` as an authorised redirect URI.

   **This previously required `/etc/soc2/soc2-secrets.conf`**, injecting the same values as
   `fastcgi_param`s. That file was never created, so `nginx.conf` could not load its
   `include`, so the deployed config was an older one with no `auth_request` at all — and
   the portal served the entire audit package over plain HTTP to the internet,
   unauthenticated, from 2026-07-13 until 2026-08-26. A required file that nothing creates
   is not a control; it is a way for a control to be absent while looking configured.

   Any `SOC2_*` value may still be supplied as a `fastcgi_param` and takes precedence over
   the host config. That is the way to set the optional
   `SOC2_ALLOWED_EMAILS="a@accessrrs.com,b@accessrrs.com"`, which narrows access further:
   when it is populated, both the Workspace domain **and** the explicit list must match.
   Left unset, the hosted-domain check alone applies.
3. Keep `data/smtp.json`, `data/evidence.json`, `data/change_population.json`, and uploaded evidence out of Git and include them in encrypted backups.
4. Run the ticket export as a read-only database user:

   `php scripts/export_change_population.php --env="$HOME/.env" --since="YYYY-MM-DD"`

The API accepts state-changing requests only when the same-origin client sends `X-SOC2-Request: 1`. Nginx denies direct access to runtime data, uploads, scripts, and repository metadata.

## Filing evidence

There are **exactly two ways**, and both execute the same code — `evidence_create()` in
[`api/evidence_store.php`](api/evidence_store.php):

| Who | How |
|---|---|
| A person, from a browser | POST `multipart/form-data` to `api/evidence.php` (behind Google OAuth) |
| A script, on the portal host | `sudo -u www-data php scripts/file_evidence.php --help` |

`evidence_create()` is the only place that validates the artifact, writes it into `uploads/`,
appends to `data/evidence.json`, and links the record id into `controls.json` and
`audit_tests.json`. **If you need a third way, add another caller of `evidence_create()`** — do
not reimplement the sequence, and do not add a token endpoint that bypasses `auth_request`.

Why the CLI does not go over HTTP: it is not a client. It is the portal's own code writing the
portal's own files, on the portal's own host, as the user php-fpm runs as. Every `/api/*.php`
is behind OAuth and must stay that way — this host also serves mail and webmail — so a bypass
token would trade a real authentication control for a convenience the local path does not need.

Run it as `www-data`, or the record lands unreadable by the portal:

```
cd /var/www/SOC2
sudo -u www-data php scripts/file_evidence.php \
  --file=/tmp/patch-compliance-2026-08-27.csv \
  --controls=CC7.3 --date=2026-08-27 \
  --source=scripts/export_patch_compliance.php \
  --description='Monthly patch compliance export, reviewed 2026-08-27. ... Remediation: RRS-000155.' \
  --apply
```

Without `--apply` it is a dry run. `--description` is mandatory because
`data/evidence_requirements.json` asks for the review date and remediation ticket numbers, and
a record without them is not evidence that anyone reviewed anything.

**Neither path closes the task the evidence belongs to.** Filing an artifact is not reviewing
it; a tool that did both would mean nobody looked. Attach the evidence, then close the task.

This existed nowhere until 2026-08-27, which is why `data/evidence.json` did not exist months
after the portal went up: every piece of recurring evidence the portal tracks is produced by a
script, and the only filing path required a browser.
