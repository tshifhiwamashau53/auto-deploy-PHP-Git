# auto-deploy-PHP-Git

Complete step-by-step README for installing, configuring, testing, and troubleshooting this repository's auto-deploy webhook and CLI deployment tooling.

> Summary

This project provides a small PHP webhook and shell-based deployment tool that can be installed on Debian/Ubuntu or RHEL/CentOS-like systems. It supports running composer/node tasks, validating Git HMAC signatures, and safe deployments with atomic releases, healthchecks, and optional rollback.

This README collects all instructions you need so you won't get lost when you start.

---

## Important changes / Quick notes

Important: this installer now expects you to do two extra steps so the webhook works securely and predictably on a server:

1. Run `composer install` in this repo so `vendor/autoload.php` and the PHP CLI (`bin/deploy`) work.
2. Install a small wrapper (`/usr/local/bin/auto-deploy-run`) and add a tightly-scoped sudoers entry that lets the webserver run only that wrapper as the non-root `deploy` user. This keeps the webhook process from executing arbitrary commands and makes the sudoers policy easy to audit.

(You can find copy-paste snippets for both the wrapper and the sudoers entry in the "Make it work on the server" section below.)

---

## Quick start (tested flow)

1. Clone the repo to your server (example path /opt/auto-deploy):

   ```bash
   sudo git clone https://github.com/tshifhiwamashau53/auto-deploy-PHP-Git.git /opt/auto-deploy
   cd /opt/auto-deploy
   ```

2. Run the installer as root (example):

   ```bash
   sudo bash /opt/auto-deploy/install.sh /opt/auto-deploy www-data deploy --with-composer --with-node
   ```

   - Arguments: `<install-path> <web-user> <deploy-user>`
   - Flags: `--with-composer` installs/uses composer during deploy, `--with-node` installs/uses node/npm during deploy.

3. IMPORTANT: after cloning, run composer in the tool repo (see Make it work on the server below).

4. Configure environment variables (see Configuration below). Restart services if you change PHP-FPM or web server config.

5. Configure a GitHub webhook on your repository (see Webhook setup below) and test it.

---

## Requirements / Prerequisites

- A Linux server (Debian/Ubuntu or RHEL/CentOS-like). The installer attempts to autodetect package manager (apt, yum, dnf).
- Git installed and network access to GitHub.
- A system user for the webserver (example: `www-data`, `nginx`, `apache`) and a dedicated deploy user (example: `deploy`).
- PHP CLI and PHP-FPM (version compatible with your app). The installer tries to install PHP packages, but on RHEL/CentOS you may need EPEL/Remi.
- Optional: composer (for PHP dependencies) and node/npm (for frontend builds).

---

## Make it work on the server (exact copy-paste snippets)

These are the exact files and commands we recommend creating on the server. Adjust paths and usernames to match your environment.

1) Wrapper script (recommended)

Create `/usr/local/bin/auto-deploy-run` (root-owned, exact path used in sudoers):

```bash
#!/usr/bin/env bash
# wrapper: run the AutoDeploy PHP CLI from the installed repo
# Usage: /usr/local/bin/auto-deploy-run <command> <repo> [branch]
exec /usr/bin/php /opt/auto-deploy/bin/deploy "$@"
```

Then make it executable:

```bash
sudo chmod 750 /usr/local/bin/auto-deploy-run
```

2) Sudoers snippet (tight, exact permission)

Create `/etc/sudoers.d/deploy-auto` using visudo and the exact contents below (DO NOT use wildcards):

```text
# Allow webserver (www-data) to run only the wrapper as deploy user, no password
Defaults:www-data !requiretty
www-data ALL=(deploy) NOPASSWD: /usr/local/bin/auto-deploy-run
```

Save with `sudo visudo -f /etc/sudoers.d/deploy-auto` and ensure the file mode is `0440`.

3) PHP-FPM pool environment variables (example)

Add these `env[...]` lines to your PHP-FPM pool file (e.g. `/etc/php/<version>/fpm/pool.d/www.conf`) and restart PHP-FPM.

```ini
; add these lines in your pool config and restart php-fpm
env[GITHUB_WEBHOOK_SECRET] = "your_secret_here"
env[DEPLOY_BRANCH]        = main
env[DEPLOY_USER]          = deploy
env[REPO_DIR]             = /var/www/myapp
env[DEPLOY_LOGFILE]       = /var/log/auto-deploy/deploy.log
```

4) Composer install (required for webhook and PHP CLI)

From the auto-deploy tool directory on the server, run (as the `deploy` user):

```bash
cd /opt/auto-deploy
sudo -u deploy composer install --no-dev --prefer-dist --optimize-autoloader
```

This creates `vendor/autoload.php` required by the webhook and CLI.

5) Manual deploy test (copy/paste)

Run the same command the webhook will run. Replace repo path and branch as needed:

```bash
# Run as the deploy user (or test as webserver user if you prefer)
sudo -u deploy /usr/local/bin/auto-deploy-run deploy /var/www/myapp main
# or test wrapper as webserver user:
sudo -u www-data /usr/local/bin/auto-deploy-run deploy /var/www/myapp main
```

6) Webhook HMAC test (simulate GitHub push)

```bash
PAYLOAD='{"ref":"refs/heads/main"}'
SECRET='your_secret_here'
SIG='sha256='$(printf "%s" "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.* //')
curl -X POST -H "X-Hub-Signature-256: $SIG" -H "Content-Type: application/json" --data "$PAYLOAD" https://your-domain/path/to/webhook.php
```

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

- `public/webhook.php` - The webhook endpoint that validates GitHub HMAC sha256 signatures and triggers the deploy command via sudo (configured via env).
- `bin/deploy` - PHP CLI deploy tool (Symfony Console) that performs atomic releases and uses `src/` classes.
- `deploy.sh` - Shell deploy helper (alternative) that performs an atomic release, healthcheck, and cleanup.
- `deploy.php` - PHP helper wrapper (example) that may also be used to call the shell script.
- `install.sh` - Installer script for the host.
- `deploy-sudoers.example` - Example sudoers line for allowing the webserver or webhook process to run the deploy command as the deploy user.
- `systemd/auto-deploy.service.example` - Example systemd unit to run the deploy task.
- `logrotate.deploy` - Example logrotate configuration for deployment logs.
- `nginx.deploy.conf` - Example nginx configuration for serving the webhook (optional).
- `bin/`, `src/` - Supporting scripts and PHP classes.

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

`public/webhook.php` expects the `X-Hub-Signature-256` header and will reject requests where the HMAC does not match.

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

## Short post-install checklist

- [ ] created non-root deploy user and added SSH deploy key (if repo private)
- [ ] cloned app to DEPLOY_PATH as deploy user
- [ ] cloned auto-deploy tool to /opt/auto-deploy and ran composer install
- [ ] created /usr/local/bin/auto-deploy-run wrapper and chmod 750
- [ ] added /etc/sudoers.d/deploy-auto with exact wrapper entry (0440)
- [ ] set PHP-FPM pool env variables and restarted php-fpm
- [ ] created /var/log/auto-deploy with correct ownership
- [ ] created GitHub webhook with matching secret
- [ ] tested manual deploy and webhook delivery

---

## Short troubleshooting hints

- 500 error from webhook → `vendor/autoload.php` missing? Run `composer install` in `/opt/auto-deploy`.
- 403 Invalid signature → webhook secret mismatch or wrong HMAC body; check PHP-FPM env and GitHub secret.
- `sudo: a password is required` → sudoers file wrong; verify exact path and run `visudo -f /etc/sudoers.d/deploy-auto`.
- `git fetch` fails → deploy user SSH key not configured or wrong permissions on `/home/deploy/.ssh`.
- No logfile entries → check `DEPLOY_LOGFILE` and that the webserver/deploy user can write it.

---

## Customization

- Edit `deploy.sh` to match your project's build/deploy steps. The included script is a simple starting point.
- If your project uses Docker, replace local compose/build steps with Docker commands in `deploy.sh`.
- Add Slack/Teams notifications in `src/Notification.php` or equivalent; the code already has a Notification class hook if you want to enable it (requires webhook URL env var).

---

## Where to look next (suggested order when starting)

1. Inspect `install.sh` and understand what it will change on your server.
2. Review `deploy-sudoers.example` and prepare `/etc/sudoers.d/deploy-auto` with correct user and script paths.
3. Run the installer on a staging server first.
4. Set `GITHUB_WEBHOOK_SECRET` and configure the GitHub webhook.
5. Test the webhook with the HMAC curl example.
6. Inspect `logs/deploy.log` and make adjustments to `deploy.sh` to match your project's needs.

---
