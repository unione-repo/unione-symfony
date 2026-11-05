<?php

declare(strict_types=1);

namespace UniOne\Wrapper\Mailer;

use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;

/**
 * A send error that a repeat can not fix (an API error other than HTTP 429/5xx, an invalid request): symfony/messenger
 * does not retry it. Thrown only when symfony/messenger is installed, else the transport throws a TransportException.
 * getPrevious() is the ApiException of the SDK.
 */
final class UnrecoverableTransportException extends TransportException implements UnrecoverableExceptionInterface
{
}
