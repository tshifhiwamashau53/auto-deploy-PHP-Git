<?php
// public/webhook.php
// Portable webhook entrypoint — verifies GitHub signature and triggers the CLI deploy in background as the deploy user.
require __DIR__ . '/../vendor/autoload.php';
use AutoDeploy\Config;
use AutoDeploy\Security;

$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null;
$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? 'push';

$secret = Config::secret();
$logPath = Config::logPath();
// ensure log dir
$dir = dirname($logPath);
if (!is_dir($dir)) mkdir($dir, 0755, true);

$logLine = function($m) use ($logPath) {
    $t = date('c');
    @file_put_contents($logPath, "[$t] $m\n", FILE_APPEND | LOCK_EX);
};

if (!Security::verifySignature($payload, $signature, $secret)) {
    http_response_code(403);
    echo "Invalid signature\n";
    $logLine('Invalid signature');
    exit;
}

$data = json_decode($payload, true);
if (!is_array($data)) {
    http_response_code(400);
    echo "Invalid payload\n";
    $logLine('Invalid JSON payload');
    exit;
}

$ref = $data['ref'] ?? '';
$parts = explode('/', $ref);
$branch = end($parts) ?: Config::env('DEPLOY_BRANCH', 'main');

// Build portable command
$php = Config::env('PHP_BIN', '/usr/bin/php');
$cli = Config::env('DEPLOY_CLI', rtrim(Config::repoDir(), '/') . '/bin/deploy');
$repo = escapeshellarg(Config::repoDir());
$log = escapeshellarg($logPath);
$deployUser = Config::deployUser();

// The sudoers file must allow EXACTLY this command for security. We background it with nohup.
$cmd = sprintf('sudo -u %s nohup %s %s deploy %s %s >> %s 2>&1 &',
    escapeshellarg($deployUser),
    escapeshellcmd($php),
    escapeshellarg($cli),
    $repo,
    escapeshellarg($branch),
    $log
);

$logLine('Executing: ' . $cmd);
exec($cmd);

http_response_code(200);
echo "Deploy triggered\n";
