# README.md

Updated multi-distro installer and portable webhook behavior have been added. This repository can now be installed on Debian/Ubuntu or RHEL/CentOS-like systems using the included install.sh and configured to trigger background deployments via the CLI.

Quick actions you can run (example)
1) Clone repo to server: sudo git clone https://github.com/tshifhiwamashau53/auto-deploy-PHP-Git.git /var/www/html/auto-deploy-PHP-Git
2) Run installer as root: sudo bash /var/www/html/auto-deploy-PHP-Git/install.sh /var/www/html/auto-deploy-PHP-Git www-data deploy --with-composer --with-node
3) Configure PHP-FPM pool environment variables (see detailed README sections already in repo)

Multi-distro notes
- install.sh detects apt vs yum/dnf and installs packages where possible. On RHEL/CentOS you may need EPEL/Remi repositories enabled for modern PHP/node packages. The script will create a deploy user, install CLI to /usr/local/bin/deploy, create sudoers entry, and setup logs.

Webhook behavior
- public/webhook.php now validates GitHub HMAC sha256 signature, and then triggers the CLI deploy command as the configured deploy user via sudo in the background (nohup + &). This avoids long-running PHP requests and runs with least privilege.

Systemd example
- A sample systemd unit is placed at systemd/auto-deploy.service.example to illustrate how to run the CLI via systemd as a one-shot task.

If you want I will:
- commit additional per-distro PHP-FPM pool snippets and nginx/apache examples for CentOS/RHEL in the README,
- or commit the exact background-exec snippet into public/webhook.php (already updated),
- or add Slack/Teams notifications into Notification class (requires a webhook URL env var).

