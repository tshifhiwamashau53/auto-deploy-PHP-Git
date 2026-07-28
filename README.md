# auto-deploy-PHP-Git

This repository now includes a minimal webhook receiver and a deploy script you can install on your server to enable automatic deployments from GitHub push events.

Files added
- deploy.php — PHP webhook receiver that validates GitHub's X-Hub-Signature-256 and runs the deploy script.
- deploy.sh — A safe deploy script that updates the repository and runs optional build/install steps.

Quick server setup
1. Clone this repository on your server (example path: /var/www/html/auto-deploy-PHP-Git):

   git clone https://github.com/tshifhiwamashau53/auto-deploy-PHP-Git.git /var/www/html/auto-deploy-PHP-Git

2. Install deploy.sh

   sudo mv deploy.sh /usr/local/bin/deploy.sh
   sudo chown root:root /usr/local/bin/deploy.sh
   sudo chmod 700 /usr/local/bin/deploy.sh

3. Configure a dedicated deploy user (recommended)

   sudo useradd -m -s /bin/bash deploy
   sudo chown -R deploy:deploy /var/www/html/auto-deploy-PHP-Git

4. Allow the webserver user (e.g., www-data) to run the script as the deploy user without a password
   (edit with `sudo visudo` and add this line — be careful with sudoers editing):

   www-data ALL=(deploy) NOPASSWD: /usr/local/bin/deploy.sh

   If you use a different webserver user, replace `www-data` accordingly.

5. Place deploy.php under a secure HTTPS-accessible location on your webserver. Example nginx location:

   location /hooks/deploy {
       include fastcgi_params;
       fastcgi_pass unix:/run/php/php-fpm.sock;
       fastcgi_param SCRIPT_FILENAME /var/www/hooks/deploy.php;
       allow 192.30.252.0/22; # (OPTIONAL) Github webhook IP ranges
       deny all;
   }

6. Set the webhook in GitHub
   - Repository > Settings > Webhooks > Add webhook
   - Payload URL: https://yourserver.example/hooks/deploy
   - Content type: application/json
   - Secret: choose a strong secret and set it in the `GITHUB_WEBHOOK_SECRET` environment variable for your webserver (or edit deploy.php to include it)
   - Which events: Just the push event

Security notes
- Always use HTTPS for the webhook endpoint.
- Restrict access by IP (GitHub's webhook IP ranges) when possible.
- Do not make the deploy script world-writable.
- Prefer running deploy.sh as a non-root dedicated deploy user and grant the webserver permission to run only that script via sudoers.

If you want, I can:
- open a pull request instead of committing to main,
- change paths/user defaults in the scripts to match your server,
- or update the webhook receiver to use a different language or method.
