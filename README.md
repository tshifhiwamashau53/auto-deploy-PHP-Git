# auto-deploy-PHP-Git — Quick setup

Quick, no fancy words. This makes GitHub push -> deploy work on a Linux server.

Quick install (run as root)
1. Clone:
   sudo git clone https://github.com/tshifhiwamashau53/auto-deploy-PHP-Git.git /opt/auto-deploy

2. Run installer (example):
   sudo bash /opt/auto-deploy/install.sh /opt/auto-deploy www-data deploy --with-composer --with-node

Set required environment variables (set these for your web process / PHP-FPM pool)
- GITHUB_WEBHOOK_SECRET  (your webhook secret)
- REPO_DIR               (path to site repository, e.g. /var/www/myapp)
- DEPLOY_USER            (system user that runs deploy, e.g. deploy)
- DEPLOY_SCRIPT          (path to deploy.sh, default /opt/auto-deploy/deploy.sh)
- DEPLOY_BRANCH          (branch to accept, default main)
- DEPLOY_LOG or DEPLOY_LOGFILE (path to deploy log)

Example PHP-FPM pool snippet (replace <PHP_VERSION> with your PHP version, e.g. 8.1):
sudo bash -c 'cat > /etc/php/<PHP_VERSION>/fpm/pool.d/auto-deploy.conf <<EOF
[auto-deploy]
user = www-data
group = www-data
env[GITHUB_WEBHOOK_SECRET] = "your_secret_here"
env[REPO_DIR] = "/var/www/myapp"
env[DEPLOY_SCRIPT] = "/opt/auto-deploy/deploy.sh"
env[DEPLOY_USER] = "deploy"
env[DEPLOY_BRANCH] = "main"
EOF'
sudo systemctl restart php<PHP_VERSION>-fpm

Allow web user to run deploy script (create sudoers file)
sudo bash -c 'cat > /etc/sudoers.d/deploy-auto <<EOF
Defaults:www-data !requiretty
www-data ALL=(deploy) NOPASSWD: /opt/auto-deploy/deploy.sh, /opt/auto-deploy/deploy.php
EOF'
sudo chmod 0440 /etc/sudoers.d/deploy-auto
sudo visudo -cf /etc/sudoers.d/deploy-auto

Test the webhook locally (compute HMAC and POST)
PAYLOAD='{ "ref":"refs/heads/main" }'
SECRET='your_secret'
SIG='sha256='$(printf "%s" "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" | sed 's/^.* //')
curl -X POST -H "X-Hub-Signature-256: $SIG" -H "Content-Type: application/json" --data "$PAYLOAD" https://your-server.example.com/webhook.php

Manual deploy commands
# run deploy script as deploy user (recommended)
sudo -u deploy -i /opt/auto-deploy/deploy.sh /var/www/myapp main

# or use PHP CLI tool
sudo -u deploy php /opt/auto-deploy/bin/deploy deploy /var/www/myapp main

Important notes (short)
- Keep GITHUB_WEBHOOK_SECRET secret.
- Review /opt/auto-deploy/deploy.sh and adapt to your app before enabling.
- Use visudo and check sudoers file (we validated it above).
- Serve webhook over HTTPS and limit access if possible.
