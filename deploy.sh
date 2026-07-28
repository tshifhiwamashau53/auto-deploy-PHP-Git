#!/usr/bin/env bash
# deploy.sh
# Robust, production-ready deployment script with atomic releases, locking, healthchecks, and rollback.
# Usage (when installed to /usr/local/bin):
#   sudo -u deploy /usr/local/bin/deploy.sh /var/www/html/auto-deploy-PHP-Git main
# Always run as the dedicated deploy user (sudoers should allow the webserver to run this single script).

set -euo pipefail

REPO_DIR_ARG=${1:-}
BRANCH_ARG=${2:-main}
KEEP_RELEASES=${KEEP_RELEASES:-5}
DEPLOY_USER=${DEPLOY_USER:-deploy}
RESTART_COMMAND=${RESTART_COMMAND:-}
HEALTHCHECK_URL=${HEALTHCHECK_URL:-}
HEALTHCHECK_TIMEOUT=${HEALTHCHECK_TIMEOUT:-10}
TIMESTAMP=$(date -u +%Y%m%dT%H%M%SZ)

if [ -z "$REPO_DIR_ARG" ]; then
  echo "Usage: $0 <repo_dir> [branch]" >&2
  exit 2
fi

REPO_DIR=$(realpath "$REPO_DIR_ARG")
BRANCH=${BRANCH_ARG}
RELEASES_DIR="$REPO_DIR/releases"
CURRENT_LINK="$REPO_DIR/current"
TMP_DIR="/tmp/deploy_${TIMESTAMP}"
LOGFILE="/var/log/deploy_${$(basename "$REPO_DIR"):-site}.log"
LOCKDIR="/var/lock/deploy_$(basename "$REPO_DIR")"

# Create logs directory if needed
mkdir -p "$(dirname "$LOGFILE")"

log() {
  echo "[$(date -u +'%Y-%m-%dT%H:%M:%SZ')] $*" | tee -a "$LOGFILE"
}

cleanup() {
  local code=$?
  if [ -d "$TMP_DIR" ]; then
    rm -rf "$TMP_DIR"
  fi
  if [ -d "$LOCKDIR" ]; then
    rm -rf "$LOCKDIR"
  fi
  exit $code
}
trap cleanup EXIT

# Lock to prevent concurrent deploys
if ! mkdir "$LOCKDIR" 2>/dev/null; then
  log "Another deploy is running (lockdir $LOCKDIR exists). Exiting."
  exit 3
fi

log "Starting deploy: repo=$REPO_DIR branch=$BRANCH user=$(whoami)"

# Ensure REPO_DIR exists and get remote URL
if [ -d "$REPO_DIR/.git" ]; then
  REMOTE_URL=$(git -C "$REPO_DIR" config --get remote.origin.url || true)
else
  REMOTE_URL=""
fi

if [ -z "$REMOTE_URL" ]; then
  log "No remote URL found in $REPO_DIR; attempting to use current as remote source"
  if [ -L "$CURRENT_LINK" ] && [ -d "$CURRENT_LINK" ]; then
    REMOTE_URL=$(git -C "$CURRENT_LINK" config --get remote.origin.url || true)
  fi
fi

if [ -z "$REMOTE_URL" ]; then
  log "ERROR: Could not determine remote repository URL. Ensure $REPO_DIR is a git clone or set up a remote."
  exit 4
fi

log "Using remote: $REMOTE_URL"

# Prepare tmp clone
log "Creating temporary clone at $TMP_DIR"
if ! git clone --depth 1 --branch "$BRANCH" "$REMOTE_URL" "$TMP_DIR"; then
  log "git clone failed"
  exit 5
fi

cd "$TMP_DIR"

# Install composer if applicable
if [ -f composer.json ] && command -v composer >/dev/null 2>&1; then
  log "Running composer install --no-dev --optimize-autoloader"
  composer install --no-interaction --no-progress --no-dev --optimize-autoloader
fi

# Build frontend assets if package.json exists
if [ -f package.json ] && command -v npm >/dev/null 2>&1; then
  log "Running npm ci && npm run build"
  npm ci
  if npm run build --silent; then
    log "npm build completed"
  else
    log "npm build failed (continuing)"
  fi
fi

# Prepare release directory
mkdir -p "$RELEASES_DIR"
RELEASE_DIR="$RELEASES_DIR/$TIMESTAMP"
log "Moving built code to release dir $RELEASE_DIR"
mv "$TMP_DIR" "$RELEASE_DIR"
TMP_DIR=""

# Set permissions
log "Setting ownership to $DEPLOY_USER"
chown -R "$DEPLOY_USER":"$DEPLOY_USER" "$RELEASE_DIR" || log "chown failed"

# Symlink swap
log "Switching current symlink to new release"
ln -sfn "$RELEASE_DIR" "$CURRENT_LINK"

# Optional: restart service or run post-deploy hook
if [ -n "$RESTART_COMMAND" ]; then
  log "Running restart command: $RESTART_COMMAND"
  if $RESTART_COMMAND; then
    log "Restart command succeeded"
  else
    log "Restart command failed"
  fi
fi

# Healthcheck: wait a bit then curl the URL
if [ -n "$HEALTHCHECK_URL" ]; then
  log "Waiting 2s for app startup before healthcheck"
  sleep 2
  log "Checking health URL: $HEALTHCHECK_URL"
  if curl -fsS --max-time "$HEALTHCHECK_TIMEOUT" "$HEALTHCHECK_URL" >/dev/null; then
    log "Healthcheck passed"
  else
    log "Healthcheck FAILED! Rolling back to previous release"
    # Find previous release
    PREV_RELEASE=$(ls -1dt "$RELEASES_DIR"/* | sed -n '2p' || true)
    if [ -n "$PREV_RELEASE" ]; then
      ln -sfn "$PREV_RELEASE" "$CURRENT_LINK"
      if [ -n "$RESTART_COMMAND" ]; then
        log "Restarting with previous release: $RESTART_COMMAND"
        $RESTART_COMMAND || log "Restart after rollback failed"
      fi
      log "Rollback to $PREV_RELEASE completed"
    else
      log "No previous release available to roll back to"
    fi
    exit 6
  fi
fi

# Cleanup old releases
log "Cleaning up old releases, keeping $KEEP_RELEASES"
(ls -1dt "$RELEASES_DIR"/* 2>/dev/null | tail -n +$((KEEP_RELEASES+1)) | xargs -r rm -rf) || true

log "Deploy complete"

exit 0
