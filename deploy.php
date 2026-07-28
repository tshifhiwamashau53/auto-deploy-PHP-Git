<?php
// deploy.php
// Webhook receiver for GitHub push events. Validates X-Hub-Signature-256 and runs a safe deploy script.
// Place this file where your webserver can reach it (HTTPS-only). Set the GITHUB_WEBHOOK_SECRET
// environment variable or edit $SECRET below. Prefer restricting webserver to only allow this
// endpoint and allow requests from GitHub webhook IPs.

$SECRET = getenv('GITHUB_WEBHOOK_SECRET') ?: 'change_this_secret';
$ALLOWED_BRANCH = getenv('DEPLOY_BRANCH') ?: 'main';
$DEPLOY_SCRIPT = getenv('DEPLOY_SCRIPT') ?: '/usr/local/bin/deploy.sh';
$USE_SUDO = getenv('DEPLOY_USE_SUDO') ?: 'false'; // set to 'true' to use sudo -u deploy
$DEPLOY_USER = getenv('DEPLOY_USER') ?: 'deploy';
$LOGFILE = getenv('DEPLOY_LOGFILE') ?: '/var/log/webhook_deploy.log';

// Read signature header and payload
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? '';
$payload = file_get_contents('php://input');

if (!$signature) {
    http_response_code(400);
    echo "Missing signature\n";
    error_log("Missing signature\n", 3, $LOGFILE);
    exit;
}

// Validate signature
$expected = 'sha256=' . hash_hmac('sha256', $payload, $SECRET);
if (!hash_equals($expected, $signature)) {
    http_response_code(403);
    echo "Invalid signature\n";
    error_log("Invalid signature\n", 3, $LOGFILE);
    exit;
}

// Parse JSON payload
$data = json_decode($payload, true);
if (!is_array($data)) {
    http_response_code(400);
    echo "Invalid payload\n";
    error_log("Invalid payload\n", 3, $LOGFILE);
    exit;
}

// Only respond to push events
if ($event !== 'push') {
    http_response_code(202);
    echo "Event ignored: $event\n";
    error_log("Event ignored: $event\n", 3, $LOGFILE);
    exit;
}

// Confirm branch
$ref = $data['ref'] ?? '';
$parts = explode('/', $ref);
$branch = end($parts);

if ($branch !== $ALLOWED_BRANCH) {
    http_response_code(202);
    echo "Push to branch $branch ignored\n";
    error_log("Push to branch $branch ignored\n", 3, $LOGFILE);
    exit;
}

// Build command safely
$escapedScript = escapeshellcmd($DEPLOY_SCRIPT);
if (strtolower($USE_SUDO) === 'true') {
    // Run as a dedicated deploy user via sudo (configure /etc/sudoers accordingly)
    $cmd = sprintf("sudo -u %s %s 2>&1", escapeshellarg($DEPLOY_USER), $escapedScript);
} else {
    $cmd = $escapedScript . ' 2>&1';
}

// Execute and capture output
$output = [];
$returnVar = 0;
exec($cmd, $output, $returnVar);

// Log and respond
$ts = date('c');
$status = $returnVar === 0 ? 'success' : 'failure';
$logEntry = "[$ts] Deploy $status (branch=$branch, return=$returnVar)\n" . implode("\n", $output) . "\n";
error_log($logEntry, 3, $LOGFILE);

http_response_code($returnVar === 0 ? 200 : 500);
echo ($returnVar === 0 ? "Deploy finished\n" : "Deploy failed\n");

?>