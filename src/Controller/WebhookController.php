<?php

declare(strict_types=1);

namespace UniOne\Wrapper\Controller;

use UniOne\Api\Webhook\WebhookApi;
use UniOne\Wrapper\Event\WebhookEventReceivedEvent;
use UniOne\Wrapper\Event\WebhookReceivedEvent;
use UniOne\Wrapper\Webhook\WebhookSignature;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Webhook endpoint: GET/HEAD -> 200 (URL check of webhook/set), POST -> signature check, WebhookReceivedEvent and a
 * WebhookEventReceivedEvent per event. Listeners must be fast: the API waits for 200 OK for 3 seconds only.
 */
final class WebhookController
{
    public function __construct(
        private readonly ?string $webhookKey,
        private readonly string $apiKey,
        private readonly EventDispatcherInterface $dispatcher,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return new Response('OK');
        }

        $body = $request->getContent();
        $key = null !== $this->webhookKey && '' !== $this->webhookKey ? $this->webhookKey : $this->apiKey;
        if (!WebhookSignature::verify($body, $key)) {
            return new Response('Invalid signature', Response::HTTP_UNAUTHORIZED);
        }

        try {
            $callback = WebhookApi::parseCallback($body);
            /** @var array<string, mixed> $payload */
            $payload = \json_decode($body, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException|\TypeError|\ValueError) {
            return new Response('Invalid body', Response::HTTP_BAD_REQUEST);
        }

        $this->dispatcher->dispatch(new WebhookReceivedEvent($callback, $payload));
        foreach ($callback->eventsByUser ?? [] as $user) {
            foreach ($user->events ?? [] as $event) {
                $this->dispatcher->dispatch(new WebhookEventReceivedEvent($event, $user));
            }
        }

        return new Response('OK');
    }
}
