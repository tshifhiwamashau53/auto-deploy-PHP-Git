# auto-deploy-PHP-Git

Complete step-by-step README for installing, configuring, testing, and troubleshooting this repository's auto-deploy webhook and CLI deployment tooling.

> Summary

This project provides a small PHP webhook and shell-based deployment tool that can be installed on Debian/Ubuntu or RHEL/CentOS-like systems. It supports running composer/node tasks, validating GitHub HMAC signatures, and running the deploy command as a dedicated deploy user via sudo.

This README collects all instructions you need so you won't get lost when you start.

---

## Quick start (tested flow)

1. Clone the repo to your server (example path /opt/auto-deploy):

   sudo git clone https://github.com/tshifhiwamashau53/auto-deploy-PHP-Git.git /opt/auto-deploy

2. Run the installer as root (example):

   sudo bash /opt/auto-deploy/install.sh /opt/auto-deploy www-data deploy --with-composer --with-node

   - Arguments: <install-path> <web-user> <deploy-user>
   - Flags: `--with-composer` installs/uses composer during deploy, `--with-node` installs/uses node/npm during deploy.

3. Configure environment variables (see Configuration below). Restart services if you change PHP-FPM or web server config.

4. Configure a GitHub webhook on your repository (see Webhook setup below) and test it.

---

## Requirements / Prerequisites

- A Linux server (Debian/Ubuntu or RHEL/CentOS-like). The installer attempts to autodetect package manager (apt, yum, dnf).
- Git installed and network access to GitHub.
- A system user for the webserver (example: `www-data`, `nginx`, `apache`) and a dedicated deploy user (example: `deploy`).
- PHP CLI and PHP-FPM (version compatible with your app). The installer tries to install PHP packages, but on RHEL/CentOS you may need EPEL/Remi.
- Optional: composer (for PHP dependencies) and node/npm (for frontend builds).

---

## Installer (install.sh)

The included `install.sh` is a multi-distro installer. Basic usage:

  sudo bash /opt/auto-deploy/install.sh <install-path> <web-user> <deploy-user> [--with-composer] [--with-node]

What it does (high level):
- Creates the deploy user if missing and ensures correct ownership for the install path.
- Installs requested packages where possible (php, git, composer, node/npm) using apt/yum/dnf.
- Installs example systemd unit, sudoers snippet, and logrotate config into appropriate places (you must review them before enabling).

After running, inspect the install log and files under the install path before enabling services in production.

---

## Files of interest

- `public/webhook.php` - The webhook endpoint that validates GitHub HMAC sha256 signatures and triggers the deploy command via sudo.
- `deploy.sh` - Shell deploy helper that performs the actual git pull, composer install, npm build steps if enabled.
- `deploy.php` - PHP CLI helper wrapper (if used) that may call the shell script or do app-specific tasks.
- `install.sh` - Installer script for the host.
- `deploy-sudoers.example` - Example sudoers line for allowing the webserver or webhook process to run the deploy command as the deploy user.
- `systemd/auto-deploy.service.example` - Example systemd unit to run the deploy task.
- `logrotate.deploy` - Example logrotate configuration for deployment logs.
- `nginx.deploy.conf` - Example nginx configuration for serving the webhook (optional).
- `bin/` and `src/` - Supporting scripts and PHP classes.

---

## Configuration

Environment variables (recommended to set in your web server's environment or PHP-FPM pool config):

- `DEPLOY_PATH` - Full path to the cloned app you want to update (e.g. `/var/www/myapp`).
- `DEPLOY_USER` - System user that should own and perform the deploy (example `deploy`).
- `DEPLOY_GROUP` - Optional; group for the deploy user.
- `GITHUB_WEBHOOK_SECRET` - Secret string configured in GitHub webhook (used to validate payload HMAC). MUST be kept secret.
- `DEPLOY_CMD` - The command that will be invoked to deploy (default points to `deploy.sh` or `deploy.php`).
- `LOG_PATH` - Where deployment logs are written (default inside the install path `logs/`).

How to set in PHP-FPM pool file (example):

- Add to `/etc/php/<version>/fpm/pool.d/www.conf` (or a separate pool):

  env[DEPLOY_PATH] = /var/www/myapp
  env[DEPLOY_USER] = deploy
  env[GITHUB_WEBHOOK_SECRET] = "your_secret_here"

Restart PHP-FPM after changes.

---

## Sudoers (deploy-sudoers.example)

You must allow the web server or PHP process to trigger the deploy command without a password, but limit the allowed commands.

Example sudoers line (DO NOT add general ALL privileges):

  # Allow www-data to run the deploy script as deploy user without password and without requiring a tty
  Defaults:www-data !requiretty
  www-data ALL=(deploy) NOPASSWD: /opt/auto-deploy/deploy.sh, /opt/auto-deploy/deploy.php

Install by editing `/etc/sudoers.d/deploy-auto` (create as root). Always use visudo to edit or validate:

  sudo visudo -f /etc/sudoers.d/deploy-auto

Paste the exact lines and save. Ensure file permissions are 0440.

---

## Webhook setup (GitHub)

1. In your GitHub repository: Settings -> Webhooks -> Add webhook.
2. Payload URL: `https(s)://your-server.example.com/webhook.php` (or the public path where `public/webhook.php` is served).
3. Content type: `application/json`.
4. Secret: the same value you set in `GITHUB_WEBHOOK_SECRET`.
5. Choose events: `Just the push event` is usually sufficient.

public/webhook.php expects the `X-Hub-Signature-256` header and will reject requests where the HMAC does not match.

Testing the webhook locally (example):

Compute HMAC signature and send a test payload with curl:

  PAYLOAD='{ "ref": "refs/heads/main" }'
  SECRET='your_secret'
  SIG='sha256='$(printf "%s" "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.* //')

  curl -X POST -H "X-Hub-Signature-256: $SIG" -H "Content-Type: application/json" --data "$PAYLOAD" https://your-server.example.com/webhook.php

If the webhook is accepted, it should trigger the deploy command (run via sudo as configured).

---

## Deploy command behavior

- When a valid webhook arrives, `public/webhook.php` validates the signature and then runs the configured `DEPLOY_CMD` as the `DEPLOY_USER` via sudo using a backgrounded call (nohup + &).
- The deploy script (`deploy.sh`) should perform:
  - `git fetch` / `git reset --hard origin/<branch>` or `git pull` depending on configuration
  - `composer install --no-dev --optimize-autoloader` if `--with-composer` enabled
  - `npm ci && npm run build` if `--with-node` enabled (or your project's build steps)
  - Clear caches or run migrations if your app requires it (customize these steps)
- Logs should be appended to `logs/deploy.log` or to the path you configured.

---

## Systemd (example)

If you prefer systemd to execute deploy actions (one-shot), see `systemd/auto-deploy.service.example`. Example usage:

  sudo cp systemd/auto-deploy.service.example /etc/systemd/system/auto-deploy.service
  sudo systemctl daemon-reload
  sudo systemctl enable --now auto-deploy.service

Edit the Service unit to point to the correct paths and user. For one-shot runs you may prefer a timer unit that triggers after a webhook writes a trigger file — this repo only contains an example.

---

## Log rotation

Copy `logrotate.deploy` to `/etc/logrotate.d/auto-deploy` and adjust the `log` path inside to match your install. Example config is safe and rotates weekly.

---

## Nginx / Apache notes

- `nginx.deploy.conf` is an example server block that serves the webhook endpoint over HTTPS. Ensure TLS termination is configured and only allow POST requests to `/webhook.php`.
- Ensure the public directory is owned by the `www-data` (or your web user) and that `deploy` user can write where necessary (use group permissions or setfacl where appropriate).

---

## Permissions and ownership

- Recommended ownership for the deployed app tree: `chown -R deploy:www-data /var/www/myapp` and set directories writable where needed.
- For safe deployment, `deploy` should own the repository directory; the web-user only needs read access unless your app writes files at runtime.
- Avoid using `root` for daily deploy operations.

---

## Testing & Troubleshooting

- Check webhook delivery logs in GitHub (Repository -> Settings -> Webhooks -> Select webhook -> Deliveries) for payload and response codes.
- Look at the web server error/access logs and `logs/deploy.log` for deploy script output.
- If webhook fails HMAC validation: verify `GITHUB_WEBHOOK_SECRET` matches and that HMAC is computed using the raw request body.
- If sudo fails: check `/etc/sudoers.d/deploy-auto`, verify NOPASSWD lines, permissions 0440, and that the username and script paths are exact.
- If deploy script fails: try running the same command manually as the deploy user:

  sudo -u deploy -i /opt/auto-deploy/deploy.sh

- If composer/npm fail: ensure system PATH for the deploy user includes composer/node, or use absolute paths in `deploy.sh`.

---

## Rollback strategy

- Keep backups of critical files and database before running deploys (if your deploy runs DB migrations, migrate in a controlled step).
- You can revert to the previous commit with git, for example, from the `DEPLOY_PATH` as `deploy` user:

  sudo -u deploy -i bash -c "cd $DEPLOY_PATH && git reset --hard HEAD@{1}"

- Implement maintenance mode in your app or use a load-balancer to drain traffic before risky deploys.

---

## Security notes

- The webhook secret must remain secret. Do not commit it to the repository. Store in environment variables or a secrets manager.
- Limit allowed sudo commands to only the deploy scripts and use NOPASSWD only on those commands.
- Serve the webhook endpoint over HTTPS and restrict access by IP or additional authentication if possible.
- Consider rate-limiting or filtering incoming requests to the webhook endpoint.

---

## Customization

- Edit `deploy.sh` to match your project's build/deploy steps. The included script is a simple starting point.
- If your project uses Docker, replace local compose/build steps with Docker commands in `deploy.sh`.
- Add Slack/Teams notifications in `src/Notification.php` or equivalent; the code already has a Notification class hook if you want to enable it (requires webhook URL env var).

---

## Common commands reference

Clone repo to desired path:

  sudo git clone https://github.com/tshifhiwamashau53/auto-deploy-PHP-Git.git /opt/auto-deploy

Run installer:

  sudo bash /opt/auto-deploy/install.sh /opt/auto-deploy www-data deploy --with-composer --with-node

Run deploy manually as deploy user:

  sudo -u deploy -i /opt/auto-deploy/deploy.sh

Compute HMAC and test webhook:

  PAYLOAD='{ "ref":"refs/heads/main" }'
  SECRET='your_secret'
  SIG='sha256='$(printf "%s" "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.* //')
  curl -X POST -H "X-Hub-Signature-256: $SIG" -H "Content-Type: application/json" --data "$PAYLOAD" https://your-server.example.com/webhook.php

---

## FAQ

Q: Can I run this on shared hosting?
A: Only if the host allows creation of system users and running custom sudoers entries; many shared hosts won't allow this. Prefer VPS or dedicated host.

Q: Will this run on RHEL/CentOS?
A: Yes, but you may need to enable EPEL/Remi repos for modern PHP and Node packages. The installer logs will note missing packages.

Q: How do I add notifications?
A: Add a webhook URL into Notification class env var and enable sending in the deploy flow; see `src/` for hints.

---

## Where to look next (suggested order when starting)
1. Inspect `install.sh` and understand what it will change on your server.
2. Review `deploy-sudoers.example` and prepare `/etc/sudoers.d/deploy-auto` with correct user and script paths.
3. Run the installer on a staging server first.
4. Set `GITHUB_WEBHOOK_SECRET` and configure the GitHub webhook.
5. Test the webhook with the HMAC curl example.
6. Inspect `logs/deploy.log` and make adjustments to `deploy.sh` to match your project's needs.

---

If you'd like, I can:
- Commit a per-distro PHP-FPM pool example and nginx/apache examples for CentOS/RHEL into the repo,
- Add Slack/Teams notification implementation into `src/Notification.php` with instructions,
- Add an automated systemd timer example or a webhook-to-systemd trigger snippet.

Tell me which of the above you want and I'll add it.
