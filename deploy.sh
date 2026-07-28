#!/usr/bin/env bash
# deploy.sh
# A safe deployment script to be run by a dedicated deploy user. Make executable and owned by root or the deploy user.
# Example installation:
# - place at /usr/local/bin/deploy.sh
# - chown root:root /usr/local/bin/deploy.sh
# - chmod 700 /usr/local/bin/deploy.sh
# - allow webserver to run it as the deploy user via sudoers (see README.md)

set -euo pipefail

# CONFIG (edit to match your server)
REPO_DIR="/var/www/html/auto-deploy-PHP-Git"   # path to your cloned repo on the server
BRANCH="main"
DEPLOY_USER="deploy"                            # user that should own files and run app
LOGFILE="/var/log/deploy_script.log"

# Logging helper
log() {
  echo "[$(date -u +'%Y-%m-%dT%H:%M:%SZ')] $*" | tee -a "$LOGFILE"
}

log "Starting deploy for $REPO_DIR on branch $BRANCH"

if [ ! -d "$REPO_DIR" ]; then
  log "Error: repository directory $REPO_DIR does not exist"
  exit 1
fi

cd "$REPO_DIR"

# Ensure we're on the desired branch and up-to-date
log "Fetching from origin..."
# fetch and prune
if git fetch origin --prune; then
  log "Fetch completed"
else
  log "git fetch failed"
  exit 1
fi

# Reset to remote branch to ensure working tree matches remote
log "Resetting to origin/$BRANCH..."
git reset --hard "origin/$BRANCH"

# Optional: run composer if present
if command -v composer >/dev/null 2>&1 && [ -f composer.json ]; then
  log "Running composer install --no-dev --optimize-autoloader"
  composer install --no-interaction --no-progress --no-dev --optimize-autoloader
fi

# Optional: build front-end assets
if [ -f package.json ] && command -v npm >/dev/null 2>&1; then
  log "Installing Node modules and building assets"
  npm ci
  npm run build || log "npm run build failed (continuing)"
fi

# Optional: run database migrations (uncomment if applicable)
# log "Running database migrations"
# php artisan migrate --force

# Fix permissions (adjust webserver user/group as needed)
log "Adjusting ownership and permissions"
chown -R "$DEPLOY_USER":"$DEPLOY_USER" "$REPO_DIR" || log "chown failed"
find "$REPO_DIR" -type f -exec chmod 644 {} \; || log "chmod files failed"
find "$REPO_DIR" -type d -exec chmod 755 {} \; || log "chmod dirs failed"

log "Deploy finished successfully"
exit 0
