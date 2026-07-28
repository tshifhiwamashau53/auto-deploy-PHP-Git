#!/usr/bin/env bash
# install.sh
# Helper to install the deployment scripts onto the server. Run as root.
# This script will:
# - create a deploy user (if missing)
# - move deploy.sh to /usr/local/bin and set permissions
# - create releases directory and set ownership
# - create a sudoers file allowing the webserver user to run the deploy script as the deploy user
# - print next steps (configure webserver, webhook secret)

set -euo pipefail

REPO_DIR=${1:-/var/www/html/auto-deploy-PHP-Git}
WEB_USER=${2:-www-data}
DEPLOY_USER=${3:-deploy}
DEPLOY_SCRIPT_DEST=/usr/local/bin/deploy.sh
SUDOERS_FILE=/etc/sudoers.d/deploy_auto

if [ "$(id -u)" -ne 0 ]; then
  echo "Please run as root: sudo bash install.sh /path/to/repo [web_user] [deploy_user]"
  exit 1
fi

echo "Creating deploy user if missing: $DEPLOY_USER"
if ! id -u "$DEPLOY_USER" >/dev/null 2>&1; then
  useradd -m -s /bin/bash "$DEPLOY_USER"
  echo "User $DEPLOY_USER created"
else
  echo "User $DEPLOY_USER already exists"
fi

# Move deploy.sh to /usr/local/bin
if [ ! -f "./deploy.sh" ]; then
  echo "deploy.sh not found in current directory. Run this script from the repository root where deploy.sh exists."
  exit 2
fi

echo "Installing deploy.sh to $DEPLOY_SCRIPT_DEST"
install -m 700 -o root -g root deploy.sh "$DEPLOY_SCRIPT_DEST"

# Create releases directory and set ownership
mkdir -p "$REPO_DIR/releases"
chown -R "$DEPLOY_USER":"$DEPLOY_USER" "$REPO_DIR"

# Sudoers: allow webserver user to run only the deploy script as DEPLOY_USER
cat > "$SUDOERS_FILE" <<EOF
# Allow webserver to run deploy.sh as $DEPLOY_USER without password
$WEB_USER ALL=( $DEPLOY_USER ) NOPASSWD: $DEPLOY_SCRIPT_DEST
EOF
chmod 440 "$SUDOERS_FILE"

echo "Created sudoers file at $SUDOERS_FILE"

echo "Installation complete. Next steps (must be done manually):"
cat <<STEPS
1) Configure your webserver to serve deploy.php via HTTPS and restrict access to GitHub webhook IPs if possible.
   Example nginx location is provided in README.md.
2) Set the environment variables for your webserver process (for deploy.php):
   - GITHUB_WEBHOOK_SECRET (strong random string)
   - DEPLOY_SCRIPT (default /usr/local/bin/deploy.sh)
   - DEPLOY_USER (default deploy)
   - REPO_DIR (path to repo, defaults to $REPO_DIR)
   - DEPLOY_BRANCH (branch to deploy, default main)
3) Create the GitHub webhook pointing to your deploy.php URL with the secret.
4) Test by pushing to the configured branch and watch /var/log/deploy_*.log and webhook log.
STEPS

