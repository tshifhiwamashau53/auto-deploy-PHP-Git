<?php
namespace AutoDeploy;

use Monolog\Logger;

class WebhookHandler
{
    private Logger $logger;
    private Deployer $deployer;
    private Notification $notifier;

    public function __construct(Logger $logger, Deployer $deployer, Notification $notifier)
    {
        $this->logger = $logger;
        $this->deployer = $deployer;
        $this->notifier = $notifier;
    }

    public function handle(array $payload, string $event, string $signature): int
    {
        $ref = $payload['ref'] ?? '';
        $parts = explode('/', $ref);
        $branch = end($parts);
        $allowed = Config::env('DEPLOY_BRANCH', 'main');
        if ($branch !== $allowed) {
            $this->logger->info("Ignoring push to branch $branch (allowed: $allowed)");
            return 202;
        }

        $repoDir = Config::repoDir();
        $this->logger->info('Triggering deploy for branch ' . $branch);

        $ret = $this->deployer->deploy($repoDir, $branch);
        return $ret;
    }
}
