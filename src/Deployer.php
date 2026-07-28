<?php
namespace AutoDeploy;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;

class Deployer
{
    private Logger $logger;
    private Notification $notifier;

    public function __construct(Logger $logger, Notification $notifier)
    {
        $this->logger = $logger;
        $this->notifier = $notifier;
    }

    public function ensureDirectories(): void
    {
        $releases = Config::releasesDir();
        if (!is_dir($releases)) {
            mkdir($releases, 0755, true);
        }
    }

    public function deploy(string $repoDir, string $branch = 'main'): int
    {
        $this->ensureDirectories();
        $this->logger->info("Starting deploy: repoDir={$repoDir} branch={$branch}");

        // Determine remote origin
        $remote = $this->detectRemote($repoDir);
        if (!$remote) {
            $this->logger->error('Could not detect remote origin URL');
            return 1;
        }

        $timestamp = gmdate('Ymd\THis\Z');
        $releaseDir = Config::releasesDir() . "/{$timestamp}";

        // Clone shallow
        $cmd = sprintf('git clone --depth 1 --branch %s %s %s', escapeshellarg($branch), escapeshellarg($remote), escapeshellarg($releaseDir));
        $this->logger->info("Cloning: $cmd");
        exec($cmd, $out, $ret);
        if ($ret !== 0) {
            $this->logger->error('git clone failed: ' . implode("\n", $out));
            return 2;
        }

        // Optional build steps
        if (file_exists($releaseDir . '/composer.json') && $this->commandExists('composer')) {
            $this->logger->info('Running composer install');
            exec(sprintf('cd %s && composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader 2>&1', escapeshellarg($releaseDir)), $cOut, $cRet);
            if ($cRet !== 0) {
                $this->logger->error('composer install failed: ' . implode("\n", $cOut));
            }
        }

        if (file_exists($releaseDir . '/package.json') && $this->commandExists('npm')) {
            $this->logger->info('Running npm ci && npm run build');
            exec(sprintf('cd %s && npm ci --silent && npm run build --silent 2>&1', escapeshellarg($releaseDir)), $nOut, $nRet);
            if ($nRet !== 0) {
                $this->logger->warning('npm build returned non-zero: ' . implode("\n", $nOut));
            }
        }

        // Adjust permissions
        $deployUser = Config::deployUser();
        exec(sprintf('chown -R %s:%s %s', escapeshellarg($deployUser), escapeshellarg($deployUser), escapeshellarg($releaseDir)));

        // Atomic symlink
        $current = Config::currentLink();
        $this->logger->info("Switching current symlink to $releaseDir");
        symlink($releaseDir, $current . '_tmp');
        // Use rename to be atomic
        rename($current . '_tmp', $current);

        // Restart if configured
        $restart = Config::restartCommand();
        if ($restart) {
            $this->logger->info("Running restart command: $restart");
            exec($restart . ' 2>&1', $rOut, $rRet);
            if ($rRet !== 0) {
                $this->logger->warning('Restart command failed: ' . implode("\n", $rOut));
            }
        }

        // Healthcheck
        $health = Config::healthcheckUrl();
        if ($health) {
            $this->logger->info("Running healthcheck: $health");
            $ok = $this->healthcheck($health, Config::healthcheckTimeout());
            if (!$ok) {
                $this->logger->error('Healthcheck failed; initiating rollback');
                $this->notifier->notify('error', 'Healthcheck failed after deploy, rolling back');
                $rb = new Rollback($this->logger, $this->notifier);
                $rb->rollback(Config::repoDir());
                return 3;
            }
        }

        // Prune old releases
        $this->pruneReleases(Config::keepReleases());

        $this->logger->info('Deploy succeeded');
        $this->notifier->notify('info', "Deploy succeeded: {$releaseDir}");
        return 0;
    }

    private function detectRemote(string $repoDir): ?string
    {
        if (is_dir($repoDir . '/.git')) {
            exec(sprintf('git -C %s config --get remote.origin.url', escapeshellarg($repoDir)), $out, $ret);
            if ($ret === 0 && !empty($out[0])) return trim($out[0]);
        }
        return null;
    }

    private function commandExists(string $cmd): bool
    {
        exec('command -v ' . escapeshellarg($cmd) . ' >/dev/null 2>&1', $o, $r);
        return $r === 0;
    }

    private function healthcheck(string $url, int $timeout = 10): bool
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_FAILONERROR, true);
        $res = curl_exec($ch);
        $err = curl_errno($ch);
        curl_close($ch);
        return $err === 0;
    }

    private function pruneReleases(int $keep): void
    {
        $dir = Config::releasesDir();
        $items = glob($dir . '/*', GLOB_ONLYDIR);
        if (!$items) return;
        usort($items, function ($a, $b) { return strcmp($b, $a); });
        $toRemove = array_slice($items, $keep);
        foreach ($toRemove as $d) {
            $this->logger->info('Removing old release: ' . $d);
            $this->rrmdir($d);
        }
    }

    private function rrmdir($dir)
    {
        if (!is_dir($dir)) return;
        $it = new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) rmdir($file->getRealPath()); else unlink($file->getRealPath());
        }
        rmdir($dir);
    }
}
