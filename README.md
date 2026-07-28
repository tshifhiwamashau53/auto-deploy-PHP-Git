# auto-deploy-PHP-Git — Plug & Play Production Deployment

This repository now contains a production-ready, plug-and-play auto-deployment system suitable for deploying a PHP website from GitHub to a Linux server.

What I added
- deploy.php — webhook receiver that validates GitHub signature and triggers a background deploy.
- deploy.sh — robust deploy script: atomic releases, locking, composer/npm support, healthcheck, rollback, and cleanup.
- install.sh — server-side helper to install the script, create users, and configure sudoers.
- README (this file) — instructions and examples.

High-level flow
1) GitHub push -> webhook -> deploy.php (validates signature)
2) deploy.php triggers sudo -u deploy /usr/local/bin/deploy.sh <repo_dir> <branch> in the background
3) deploy.sh clones a fresh copy of the branch into a timestamped release dir
4) build steps run (composer, npm) if present
5) release dir is atomically symlinked to current
6) optional healthcheck runs; if it fails, script rolls back to previous release
7) old releases are pruned

Important: manual server setup
Even with these files in the repo, you still must run server-side setup. Use install.sh to automate most steps (run as root on server):

sudo bash install.sh /var/www/html/auto-deploy-PHP-Git www-data deploy

This will install deploy.sh to /usr/local/bin and create a sudoers entry allowing the webserver user to run deploy.sh as the deploy user.

Webserver (nginx) example for deploy.php
Place deploy.php somewhere on the filesystem (e.g. /var/www/hooks/deploy.php) and expose via a secure location in your nginx config:

server {
  listen 443 ssl;
  server_name deploy.example.com;

  # SSL config omitted — use valid certs (Let\'s Encrypt etc.)

  location = /hooks/deploy {
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php-fpm.sock; # adjust socket/path for your system
    fastcgi_param SCRIPT_FILENAME /var/www/hooks/deploy.php;
    # OPTIONAL: restrict GitHub webhook IP ranges (keep updated)
    allow 185.199.108.0/22;
    allow 140.82.112.0/20;
    allow 192.30.252.0/22;
    deny all;
  }
}

Sudoers note (installed by install.sh)
A file /etc/sudoers.d/deploy_auto is created to allow the webserver user (www-data by default) to execute only the deploy script as the deploy user without a password. Do NOT broaden this permission.

Environment variables for deploy.php (set these for your webserver/PHP-FPM process)
- GITHUB_WEBHOOK_SECRET — REQUIRED. A strong random secret string used to validate GitHub webhooks.
- REPO_DIR — path to the repo on the server (e.g., /var/www/html/auto-deploy-PHP-Git)
- DEPLOY_BRANCH — branch to deploy (default: main)
- DEPLOY_SCRIPT — path to the deploy script (default: /usr/local/bin/deploy.sh)
- DEPLOY_USER — the non-root user to run deployments as (default: deploy)
- DEPLOY_LOGFILE — where the webhook logs are written (default: /var/log/webhook_deploy.log)

Configurable behavior in deploy.sh (via environment variables)
- KEEP_RELEASES — how many releases to keep (default: 5)
- DEPLOY_USER — who should own the files (default: deploy)
- RESTART_COMMAND — command to restart your application (optional). Example: \"systemctl restart php8.1-fpm\"
- HEALTHCHECK_URL — optional URL to curl after deployment to verify success (if set, script will rollback on failure)
- HEALTHCHECK_TIMEOUT — curl timeout in seconds (default: 10)

Post-deploy actions and safety
- The script builds composer/npm steps only if composer.json/package.json are present. You can add more hooks in the script.
- HEALTHCHECK_URL provides automatic rollback if the new release fails to respond correctly.
- Deploys are atomic (symlink swap), providing a fast rollback path.

Testing the webhook locally
You can simulate a webhook POST (compute the X-Hub-Signature-256 header) — see earlier README in repo for example curl commands.

Security checklist (must do)
- Use HTTPS for the webhook endpoint.
- Set a strong GITHUB_WEBHOOK_SECRET and configure it in GitHub's webhook settings.
- Restrict access to the hook by IP when possible.
- Keep deploy.sh permissions tight (chmod 700) and only allow the specific webserver to run it via sudoers.
- Consider further hardening: AppArmor/SELinux, network firewall, and monitoring for failed deploys.

Need help?
If you tell me the server OS (e.g., Ubuntu 22.04), webserver (nginx or apache), PHP-FPM socket path, and the service name your app uses (if any), I can:
- produce a tailored nginx/apache config,
- adapt deploy.sh to restart your service automatically,
- add systemd unit files for post-deploy tasks,
- or open a PR with any final changes you want.
