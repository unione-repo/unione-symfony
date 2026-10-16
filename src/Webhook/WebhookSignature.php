<?php

declare(strict_types=1);

namespace UniOne\Wrapper\Webhook;

/**
 * Signature of the webhook notifications: the "auth" field is the MD5 of the request body in which the "auth"
 * value is replaced by the API key of the user/project of the webhook.
 */
final class WebhookSignature
{
    public static function verify(string $body, string $apiKey): bool
    {
        if ($apiKey === '' || !\preg_match('/"auth"\s*:\s*"([0-9a-fA-F]{32})"/', $body, $match)) {
            return false;
        }

        return \hash_equals(\strtolower($match[1]), \md5(\str_replace($match[1], $apiKey, $body)));
    }

    /**
     * Signs a body that has "auth" set to any 32 hex digits placeholder, e.g. for tests.
     */
    public static function sign(string $body, string $apiKey, string $placeholder = '00000000000000000000000000000000'): string
    {
        return \str_replace($placeholder, \md5(\str_replace($placeholder, $apiKey, $body)), $body);
    }
}
