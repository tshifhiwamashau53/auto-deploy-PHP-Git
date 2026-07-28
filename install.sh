#!/usr/bin/env bash
# install.sh
# Cross-distro installer for auto-deploy system. Run as root on the target server.
# Usage: sudo bash install.sh /path/to/repo [web_user] [deploy_user] [--with-node] [--with-composer]

set -euo pipefail
REPO_DIR=${1:-/var/www/html/auto-deploy-PHP-Git}
WEB_USER=${2:-www-data}
DEPLOY_USER=${3:-deploy}
WITH_NODE=false
WITH_COMPOSER=false

for arg in "${@:4}"; do
  case "$arg" in
    --with-node) WITH_NODE=true ;;
    --with-composer) WITH_COMPOSER=true ;;
    *) echo "Unknown option: $arg" ;;
  esac
done

# helpers
log() { echo "[install] $*"; }
run_apt() { apt-get update -y; apt-get install -y "$@"; }
run_yum() { yum install -y "$@" || dnf install -y "$@"; }

if [ "$(id -u)" -ne 0 ]; then
  echo "Please run as root: sudo bash install.sh /path/to/repo [web_user] [deploy_user]"
  exit 1
fi

if [ ! -d "$REPO_DIR" ]; then
  echo "Repo directory $REPO_DIR not found. Please clone the repository first into that path." >&2
  exit 2
fi

# detect distro
. /etc/os-release || true
ID_LIKE=${ID_LIKE:-}
ID=${ID:-}
log "Detected distro: $ID (like: $ID_LIKE)"

# install packages
if command -v apt-get >/dev/null 2>&1; then
  log "Installing packages via apt"
  PKGS=(git php-cli php-fpm php-curl php-zip curl openssl)
  if $WITH_COMPOSER; then PKGS+=(composer); fi
  run_apt "${PKGS[@]}"
  if $WITH_NODE; then
    curl -fsSL https://deb.nodesource.com/setup_18.x | bash -
    apt-get install -y nodejs build-essential
  fi
elif command -v yum >/dev/null 2>&1 || command -v dnf >/dev/null 2>&1; then
  log "Installing packages via yum/dnf"
  PKGS=(git php php-cli php-fpm php-curl php-zip curl openssl)
  run_yum "${PKGS[@]}"
  if $WITH_NODE; then
    curl -fsSL https://rpm.nodesource.com/setup_18.x | bash -
    yum install -y nodejs gcc-c++ make
  fi
else
  echo "Unsupported package manager. Please install dependencies manually." >&2
fi

# create deploy user if missing
if ! id -u "$DEPLOY_USER" >/dev/null 2>&1; then
  log "Creating deploy user: $DEPLOY_USER"
  useradd -m -s /bin/bash "$DEPLOY_USER"
else
  log "Deploy user $DEPLOY_USER already exists"
fi

# install CLI
if [ -f "$REPO_DIR/bin/deploy" ]; then
  log "Installing CLI to /usr/local/bin/deploy"
  install -m 755 "$REPO_DIR/bin/deploy" /usr/local/bin/deploy
else
  log "Warning: $REPO_DIR/bin/deploy not found"
fi

# configure releases dir and current symlink
mkdir -p "$REPO_DIR/releases"
chown -R "$DEPLOY_USER":"$DEPLOY_USER" "$REPO_DIR"
ln -sfn "$REPO_DIR" "$REPO_DIR/current" || true

# sudoers entry
SUDO_FILE=/etc/sudoers.d/auto-deploy
CMD="/usr/bin/php /usr/local/bin/deploy"
log "Writing sudoers file to $SUDO_FILE (allow $WEB_USER to run: $CMD)"
cat > "$SUDO_FILE" <<EOF
# Allow webserver to run the deploy CLI as $DEPLOY_USER
$WEB_USER ALL=( $DEPLOY_USER ) NOPASSWD: $CMD
EOF
chmod 0440 "$SUDO_FILE"

# setup log file
LOG=/var/log/auto-deploy.log
touch "$LOG"
chown "$DEPLOY_USER":"$DEPLOY_USER" "$LOG"
chmod 0640 "$LOG"

# install logrotate config
LOGROTATE=/etc/logrotate.d/auto-deploy
cat > "$LOGROTATE" <<'EOF'
/var/log/auto-deploy.log /var/log/deploy_*.log {
    weekly
    rotate 6
    compress
    missingok
    notifempty
    create 640 deploy adm
    sharedscripts
}
EOF
chmod 0644 "$LOGROTATE"

# optional systemd service example
SERVICE_EXAMPLE=$REPO_DIR/systemd/auto-deploy.service.example
mkdir -p "$REPO_DIR/systemd"
cat > "$SERVICE_EXAMPLE" <<'EOF'
[Unit]
Description=Auto Deploy (example wrapper)
After=network.target

[Service]
Type=oneshot
User=deploy
Group=deploy
WorkingDirectory=/var/www/html/auto-deploy-PHP-Git
ExecStart=/usr/bin/php /usr/local/bin/deploy deploy /var/www/html/auto-deploy-PHP-Git main

[Install]
WantedBy=multi-user.target
EOF

log "Installation complete. Next steps:"
cat <<STEPS
1) Edit your PHP-FPM pool to set GITHUB_WEBHOOK_SECRET, REPO_DIR, DEPLOY_BRANCH (see README).
2) Configure your webserver to call public/webhook.php on the /hooks/deploy path (HTTPS only).
3) Add the public key for the 'deploy' user to GitHub as a Deploy Key if your site repo is private.
4) Create the GitHub webhook (payload URL -> https://yourserver/hooks/deploy, secret = GITHUB_WEBHOOK_SECRET).
5) Test by pushing to the configured branch.

If you want the script to also install Composer or Node, re-run with --with-composer and/or --with-node (if apt/yum repos are available).
STEPS

exit 0
