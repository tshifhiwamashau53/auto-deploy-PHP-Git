<?php
require __DIR__ . '/../vendor/autoload.php';

use AutoDeploy\Config;
use AutoDeploy\Security;
use AutoDeploy\Notification;
use AutoDeploy\Deployer;
use AutoDeploy\WebhookHandler;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

$logPath = Config::logPath();
$logger = new Logger('webhook');
// Ensure log dir exists
$dir = dirname($logPath);
if (!is_dir($dir)) mkdir($dir, 0755, true);
$logger->pushHandler(new StreamHandler($logPath, Logger::DEBUG));

$secret = Config::secret();
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? null;
$event = $_SERVER['HTTP_X_GITHUB_EVENT'] ?? 'push';

if (!Security::verifySignature($payload, $signature, $secret)) {
    http_response_code(403);
    echo "Invalid signature\n";
    $logger->warning('Invalid signature for incoming webhook');
    exit;
}

$data = json_decode($payload, true);
if (!is_array($data)) {
    http_response_code(400);
    echo "Invalid payload\n";
    $logger->warning('Invalid JSON payload');
    exit;
}

$notifier = new Notification($logger);
$deployer = new Deployer($logger, $notifier);
$handler = new WebhookHandler($logger, $deployer, $notifier);
$ret = $handler->handle($data, $event, $signature);

http_response_code($ret === 0 ? 200 : 500);
echo ($ret === 0 ? "Deploy OK\n" : "Deploy failed\n");
