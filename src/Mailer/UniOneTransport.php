<?php

declare(strict_types=1);

namespace UniOne\Wrapper\Mailer;

use UniOne\Client\ApiClient;
use UniOne\Client\ApiException;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\MessageConverter;

/**
 * Symfony Mailer transport that sends through email/send of the API.
 * The job_id of the API becomes the message id of the SentMessage.
 */
final class UniOneTransport extends AbstractTransport
{
    public function __construct(
        private readonly ApiClient $client,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($dispatcher, $logger);
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());
        try {
            $response = $this->client->call('/email/send.json', ['message' => EmailPayload::fromEmail($email, $message->getEnvelope())]);
        } catch (ApiException $e) {
            throw new TransportException(\sprintf('Unable to send the email: %s (code %s).', $e->getMessage(), $e->getErrorCode() ?? '-'), 0, $e);
        }
        if (isset($response['job_id']) && \is_string($response['job_id'])) {
            $message->setMessageId($response['job_id']);
        }
    }

    public function __toString(): string
    {
        return 'unione+api://default';
    }
}
