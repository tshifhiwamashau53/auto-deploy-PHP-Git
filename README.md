# auto-deploy-PHP-Git — Plug & Play Production Deployment

This repository contains a production-ready, plug-and-play auto-deployment system to deploy a PHP (or other) website from GitHub to a Linux server using a secure webhook + an atomic deploy script.

Files in this repo
- `deploy.php` — Webhook receiver that validates GitHub's `X-Hub-Signature-256` and triggers the deploy script.
- `deploy.sh` — Robust deploy script with atomic releases, locking, composer/npm support, healthcheck, rollback and cleanup.
- `install.sh` — Helper to install `deploy.sh`, create a `deploy` user, and add a minimal sudoers entry.
- `nginx.deploy.conf` — Example nginx location block for the webhook.
- `logrotate.deploy` — Example logrotate config for deployment logs.

Goal of this README
- Give a non-technical person (or a busy manager) a single set of copy/paste commands to install and configure the system on a server so pushing to GitHub automatically deploys a website.

Prerequisites (server)
- A Linux server (Ubuntu 22.04 LTS recommended in examples here).
- Root access (or sudo) to install packages and edit system files.
- A registered domain name and ability to point an A record to the server.
- Optional: If your site is private on GitHub, you will need to add a deploy key (instructions below).

High-level steps (one-line)
1. Install required packages; 2. Clone this repo on server; 3. Run `install.sh`; 4. Configure PHP-FPM env vars (webhook secret + repo path); 5. Put `deploy.php` behind HTTPS (nginx example provided); 6. Add GitHub webhook; 7. Push to the branch and watch logs.

Detailed step-by-step (copy/paste)
These commands are Ubuntu-focused. Adjust package names/paths for other distros.

1) Install required packages (Ubuntu 22.04 example)

```bash
sudo apt update
sudo apt install -y git nginx php-fpm php-cli curl openssl
# If your project uses Composer and Node builds, also install:
sudo apt install -y composer
curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash -
sudo apt-get install -y nodejs build-essential
```

2) Clone this repo to the server (choose a path)

```bash
sudo git clone https://github.com/tshifhiwamashau53/auto-deploy-PHP-Git.git /var/www/html/auto-deploy-PHP-Git
```

If you are installing the deploy code inside your website repo instead, put these files in a `deploy/` folder and adapt paths in the instructions below.

3) Run the installer (from the repo root, as root)

```bash
cd /var/www/html/auto-deploy-PHP-Git
sudo bash install.sh /var/www/html/auto-deploy-PHP-Git www-data deploy
```

What `install.sh` does
- Ensures a `deploy` user exists (creates it if missing).
- Installs `/usr/local/bin/deploy.sh` (mode 700, owned by root).
- Creates the `releases/` directory under the repo and chowns the repo to `deploy`.
- Writes `/etc/sudoers.d/deploy_auto` to allow the webserver user (default `www-data`) to run only `/usr/local/bin/deploy.sh` as the `deploy` user.

4) Place the webhook file (`deploy.php`) and secure it

```bash
sudo mkdir -p /var/www/hooks
sudo mv /var/www/html/auto-deploy-PHP-Git/deploy.php /var/www/hooks/deploy.php
sudo chown www-data:www-data /var/www/hooks/deploy.php
sudo chmod 640 /var/www/hooks/deploy.php
```

5) Generate a webhook secret and set PHP-FPM environment variables (so `deploy.php` can read them)

```bash
secret=$(openssl rand -hex 32); echo $secret
# Edit your PHP-FPM pool file, e.g. /etc/php/8.1/fpm/pool.d/www.conf
# Add the following lines (adjust PHP version and values as needed):
# env[GITHUB_WEBHOOK_SECRET] = "$secret"
# env[REPO_DIR] = "/var/www/html/auto-deploy-PHP-Git"
# env[DEPLOY_BRANCH] = "main"
# env[DEPLOY_SCRIPT] = "/usr/local/bin/deploy.sh"
# env[DEPLOY_USER] = "deploy"
# env[DEPLOY_LOGFILE] = "/var/log/webhook_deploy.log"

# Then reload PHP-FPM:
sudo systemctl reload php8.1-fpm
```

6) Configure nginx to expose the webhook endpoint (HTTPS required)
- Use your normal server block; add a location for the hook. Example location block (paste inside the server {} block):

```nginx
location = /hooks/deploy {
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php8.1-fpm.sock; # adjust to your PHP-FPM socket
    fastcgi_param SCRIPT_FILENAME /var/www/hooks/deploy.php;
    # Optional: allow only GitHub webhook IP ranges (keep updated)
    allow 185.199.108.0/22;
    allow 140.82.112.0/20;
    allow 192.30.252.0/22;
    deny all;
}
```

- Obtain TLS certificates (Let's Encrypt certbot example):

```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d deploy.example.com
sudo systemctl reload nginx
```

7) Add the GitHub webhook (UI)
- Go to your repo → Settings → Webhooks → Add webhook
  - Payload URL: https://deploy.example.com/hooks/deploy
  - Content type: application/json
  - Secret: paste the secret you generated earlier
  - Which events: Just the push event
  - Save

8) Add your website to make deployments work

There are two common ways to add the website code so the auto-deploy works. Choose the one that matches your workflow.

A) Website lives inside this repository (same repo)
- Place your site files at the repo root (replace the example README, etc.)
- Ensure the repository `origin` is the GitHub URL and `deploy.sh` can clone/pull from it.
- If your site needs Composer or Node builds, ensure `composer.json` or `package.json` are present in repo root. `deploy.sh` will run `composer install` and `npm ci && npm run build` if those files are detected.
- Push your site code to the GitHub repository's `main` branch (or whichever branch you set in `DEPLOY_BRANCH`). A push to that branch will trigger a deployment.

B) Website is in a separate repo (recommended for multi-repo workflows)
- Create a clone of the *website* repo on the server in a path you choose and set `REPO_DIR` to that path in the PHP-FPM env.
  Example:

```bash
# as deploy user (or root then chown to deploy):
sudo -u deploy git clone git@github.com:your-org/your-website.git /var/www/html/your-website
# Ensure origin is set and accessible. If private, follow private repo steps below.
```

- Set `REPO_DIR` to `/var/www/html/your-website` in the PHP-FPM env (see step 5) so `deploy.php` knows which remote to deploy.
- `deploy.sh` will detect the `origin` remote URL from the existing clone. When a webhook triggers, it will clone a fresh shallow copy from the remote and perform the deployment steps.

Private GitHub repository notes (if your website repo is private)
- Use an SSH deploy key for the `deploy` user and add the public key to GitHub repo Settings → Deploy keys (recommended with "Allow write access" unchecked unless you need it):

```bash
# on server, as root or deploy user
sudo -u deploy mkdir -p /home/deploy/.ssh
sudo -u deploy ssh-keygen -t ed25519 -C "deploy@$(hostname)" -f /home/deploy/.ssh/deploy_key -N ""
# show public key and copy it into GitHub repo Settings -> Deploy keys -> Add deploy key
sudo -u deploy cat /home/deploy/.ssh/deploy_key.pub
# ensure permissions and known hosts
sudo -u deploy chmod 600 /home/deploy/.ssh/deploy_key
sudo -u deploy ssh-keyscan github.com >> /home/deploy/.ssh/known_hosts
sudo chown -R deploy:deploy /home/deploy/.ssh
```

- Update `/var/www/html/your-website/.git/config` to use the SSH URL `git@github.com:owner/repo.git` as `origin` or clone using the SSH URL.
- Ensure the `deploy` user can access the private repo via SSH (test by `sudo -u deploy ssh -T git@github.com`).

9) Optional: configure automatic restart and healthcheck for your app
- Set `RESTART_COMMAND` to a system command that restarts your app after a release is active (e.g. `systemctl restart php8.1-fpm` or `systemctl restart myapp.service`).
- Set `HEALTHCHECK_URL` to a URL that returns 200 when the app is healthy (e.g. `https://example.com/health`). If the healthcheck fails, `deploy.sh` will attempt to rollback to the previous release.

You can set these either as environment variables for the `deploy` user, in a systemd wrapper, or directly in `/etc/environment` or similar. Example (system-wide):

```bash
# add to /etc/environment or your service environment
RESTART_COMMAND="systemctl restart php8.1-fpm"
HEALTHCHECK_URL="https://example.com/health"
KEEP_RELEASES=5
```

10) Test the setup
- Push a dummy commit to the configured branch (e.g. `main`) and watch the logs:

```bash
# from local dev machine
git commit --allow-empty -m "test deploy"
git push origin main

# on the server
sudo tail -f /var/log/webhook_deploy.log /var/log/deploy_*.log
```

- If the webhook returns `403`: secret mismatch between GitHub webhook and the `GITHUB_WEBHOOK_SECRET` env in PHP-FPM. Re-check the secret.
- If nothing triggers: check `/etc/sudoers.d/deploy_auto` contains the sudoers line for your webserver user and correct path to `/usr/local/bin/deploy.sh`.

Troubleshooting quick reference
- 403 from webhook receiver: secret mismatch.
- 500 from webhook receiver: check `/var/log/webhook_deploy.log` for errors.
- `git clone` fails: network/credentials issue or remote URL misconfigured (private repo needs deploy key).
- Build errors: ensure `composer`, `node`, `npm` are installed and versions matched.
- Healthcheck rollback: check app logs, increase healthcheck timeout or correct service start command.

Security checklist (do these before running in production)
- Use HTTPS (Let’s Encrypt) for the webhook endpoint.
- Keep `GITHUB_WEBHOOK_SECRET` private and long (we recommend 32+ byte hex).
- Restrict access to the webhook by GitHub IP ranges or firewall rules.
- Keep `deploy.sh` mode 700 and owned by root; the webserver user should be allowed to run only that one script via sudoers.
- Store application secrets (DB credentials, API keys) outside the repository (environment or vault).

What to give your boss (one-liner)
- "Push to GitHub main and the website will be deployed automatically — watch status in the server logs at /var/log/deploy_*.log or ask me for the status page."

Want me to update this README for a specific server environment?
Tell me: OS (Ubuntu 22.04, Debian 11, CentOS 8), webserver (nginx or Apache), PHP-FPM version (e.g., php8.1-fpm), and whether your website repo is public or private. I will then add exact copy/paste commands (including PHP-FPM pool lines and nginx server block).