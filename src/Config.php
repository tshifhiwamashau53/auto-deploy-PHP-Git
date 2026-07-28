<?php
namespace AutoDeploy;

class Config
{
    public static function env(string $key, $default = null)
    {
        $v = getenv($key);
        if ($v === false) return $default;
        return $v;
    }

    public static function secret(): string
    {
        return (string) self::env('GITHUB_WEBHOOK_SECRET', 'change_me');
    }

    public static function repoDir(): string
    {
        return (string) self::env('REPO_DIR', __DIR__ . '/../');
    }

    public static function releasesDir(): string
    {
        return rtrim((string) self::env('RELEASES_DIR', self::repoDir() . '/releases'), '/');
    }

    public static function currentLink(): string
    {
        return rtrim((string) self::env('CURRENT_LINK', self::repoDir() . '/current'), '/');
    }

    public static function keepReleases(): int
    {
        return (int) self::env('KEEP_RELEASES', 5);
    }

    public static function deployUser(): string
    {
        return (string) self::env('DEPLOY_USER', 'deploy');
    }

    public static function restartCommand(): ?string
    {
        return self::env('RESTART_COMMAND', null);
    }

    public static function healthcheckUrl(): ?string
    {
        return self::env('HEALTHCHECK_URL', null);
    }

    public static function healthcheckTimeout(): int
    {
        return (int) self::env('HEALTHCHECK_TIMEOUT', 10);
    }

    public static function logPath(): string
    {
        return (string) self::env('DEPLOY_LOG', '/var/log/auto-deploy.log');
    }
}
