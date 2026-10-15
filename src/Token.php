<?php

declare(strict_types=1);

namespace Systopic\Relay;

/**
 * The JWT a browser connects to the hub with (HS256, the hub's
 * `client.token.hmac_secret_key`). The hub checks the signature itself — no
 * call from the hub to PHP, none from PHP to the hub.
 */
final class Token
{
    /**
     * @param string $sub '<prefix>:<user id>', '' for an anonymous visitor
     */
    public static function connection(string $sub, int $ttl, string $secret): string
    {
        $now = time();
        return self::sign(['sub' => $sub, 'iat' => $now, 'exp' => $now + $ttl], $secret);
    }

    /** @param array<string, mixed> $claims */
    private static function sign(array $claims, string $secret): string
    {
        $head = self::base64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $body = self::base64(json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        return "$head.$body." . self::base64(hash_hmac('sha256', "$head.$body", $secret, true));
    }

    private static function base64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
