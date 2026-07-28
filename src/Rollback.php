<?php
namespace AutoDeploy;

use Monolog\Logger;

class Rollback
{
    private Logger $logger;
    private Notification $notifier;

    public function __construct(Logger $logger, Notification $notifier)
    {
        $this->logger = $logger;
        $this->notifier = $notifier;
    }

    public function rollback(string $repoDir): bool
    {
        $releases = Config::releasesDir();
        $items = glob($releases . '/*', GLOB_ONLYDIR);
        if (!$items || count($items) < 2) {
            $this->logger->error('No previous release to roll back to');
            return false;
        }
        usort($items, function ($a, $b) { return strcmp($b, $a); });
        $current = $items[0];
        $previous = $items[1];

        $currentLink = Config::currentLink();
        $this->logger->info("Rolling back: switching current to $previous");
        // atomic symlink switch
        symlink($previous, $currentLink . '_tmp');
        rename($currentLink . '_tmp', $currentLink);

        $restart = Config::restartCommand();
        if ($restart) {
            exec($restart . ' 2>&1', $out, $ret);
            if ($ret !== 0) {
                $this->logger->warning('Restart after rollback failed: ' . implode("\n", $out));
            }
        }

        $this->notifier->notify('warning', "Rolled back to $previous");
        return true;
    }
}
