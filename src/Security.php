<?php
namespace AutoDeploy;

class Security
{
    public static function verifySignature(string $payload, ?string $signature, string $secret): bool
    {
        if (!$signature) return false;
        // signature expected: sha256=...
        if (!str_starts_with($signature, 'sha256=')) return false;
        $hash = substr($signature, 7);
        $expected = hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $hash);
    }
}
