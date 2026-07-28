<?php
namespace AutoDeploy;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class Notification
{
    private Logger $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    public function notify(string $level, string $message): void
    {
        $this->logger->info("notification:$level $message");
        // Optional: add Slack or email integration using env vars
        $webhook = Config::env('NOTIFY_SLACK_WEBHOOK');
        if ($webhook) {
            // best-effort: fire-and-forget via curl
            $payload = json_encode(['text' => strtoupper($level) . ': ' . $message]);
            @file_get_contents($webhook, false, stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => $payload,
                    'timeout' => 2
                ]
            ]));
        }
    }
}
