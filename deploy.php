<?php
// deploy.php
// Webhook receiver for GitHub push events. Validates X-Hub-Signature-256 and triggers the deploy script in background.
// Designed to be minimal and safe for production use.
// Installation: put this file on your server (HTTPS only) and restrict access. Configure environment variables
// for GITHUB_WEBHOOK_SECRET, DEPLOY_SCRIPT, DEPLOY_BRANCH, DEPLOY_USER, DEPLOY_LOGFILE.

$SECRET = getenv('GITHUB_WEBHOOK_SECRET') ?: 'change_this_secret';
$ALLOWED_BRANCH = getenv('DEPLOY_BRANCH') ?: 'main';
$DEPLOY_SCRIPT = getenv('DEPLOY_SCRIPT') ?: '/usr/local/bin/deploy.sh';
$DEPLOY_USER = getenv('DEPLOY_USER') ?: 'deploy';
$DEPLOY_LOGFILE = getenv('DEPLOY_LOGFILE') ?: '/var/log/webhook_deploy.log';
$REPO_DIR = getenv('REPO_DIR') ?: '/var/www/html/auto-deploy-PHP-Git';

// Read headers and payload
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
$payload = file_get_contents('php://input');

if (!$signature) {
    http_response_code(400);
    echo "Missing signature\n";
    error_log("Missing signature\n", 3, $DEPLOY_LOGFILE);
    exit;
}

// Validate signature
$expected = 'sha256=' . hash_hmac('sha256', $payload, $SECRET);
if (!hash_equals($expected, $signature)) {
    http_response_code(403);
    echo "Invalid signature\n";
    error_log("Invalid signature\n", 3, $DEPLOY_LOGFILE);
    exit;
}

$data = json_decode($payload, true);
if (!is_array($data)) {
    http_response_code(400);
    echo "Invalid payload\n";
    error_log("Invalid payload\n", 3, $DEPLOY_LOGFILE);
    exit;
}

if ($event !== 'push') {
    http_response_code(202);
    echo "Event ignored: $event\n";
    error_log("Event ignored: $event\n", 3, $DEPLOY_LOGFILE);
    exit;
}

$ref = $data['ref'] ?? '';
$parts = explode('/', $ref);
$branch = end($parts);

if ($branch !== $ALLOWED_BRANCH) {
    http_response_code(202);
    echo "Push to branch $branch ignored\n";
    error_log("Push to branch $branch ignored\n", 3, $DEPLOY_LOGFILE);
    exit;
}

// Build a safe background command. The webserver user must be allowed to run this via sudoers.
$escapedScript = escapeshellcmd($DEPLOY_SCRIPT);
$escapedRepo = escapeshellarg($REPO_DIR);
$escapedBranch = escapeshellarg($branch);
$escapedLog = escapeshellarg($DEPLOY_LOGFILE);

// Use sudo -u to run as the deploy user without waiting; requires sudoers entry like:
// www-data ALL=(deploy) NOPASSWD: /usr/local/bin/deploy.sh
$cmd = sprintf("sudo -u %s nohup %s %s %s >> %s 2>&1 &",
    escapeshellarg($DEPLOY_USER),
    $escapedScript,
    $escapedRepo,
    $escapedBranch,
    $escapedLog
);

// Execute the background command (does not block)
exec($cmd);

http_response_code(200);
echo "Deploy triggered\n";
error_log("Deploy triggered for branch $branch\n", 3, $DEPLOY_LOGFILE);

?>