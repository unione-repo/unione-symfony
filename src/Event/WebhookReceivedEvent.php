<?php

declare(strict_types=1);

namespace UniOne\Wrapper\Event;

use UniOne\Api\Webhook\Model\WebhookCallback;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * A webhook notification with a valid signature: all its events.
 */
final class WebhookReceivedEvent extends Event
{
    /**
     * @param array<string, mixed> $payload Decoded body of the request.
     */
    public function __construct(
        public readonly WebhookCallback $callback,
        public readonly array $payload,
    ) {
    }
}
