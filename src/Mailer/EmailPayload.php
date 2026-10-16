<?php

declare(strict_types=1);

namespace UniOne\Wrapper\Mailer;

use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Converts a Symfony Email to the "message" of email/send.
 *
 * - recipients: every address of the envelope (to, cc, bcc), the display name goes to the "to_name" substitution;
 * - with cc the "To" and "CC" headers are set, so every recipient sees the same headers;
 * - attachments with an inline disposition become inline_attachments named by their Content-ID (cid:...);
 * - TagHeader -> tags, MetadataHeader -> global_metadata, X-* and List-* / In-Reply-To / References -> headers.
 */
final class EmailPayload
{
    private const PASSED_HEADERS = ['List-Unsubscribe', 'List-Subscribe', 'List-Help', 'List-Owner', 'List-Archive', 'In-Reply-To', 'References'];

    /**
     * @return array<string, mixed>
     */
    public static function fromEmail(Email $email, ?Envelope $envelope = null): array
    {
        $from = $email->getFrom()[0] ?? $envelope?->getSender();
        if (!$from instanceof Address) {
            throw new TransportException('The message has no sender (From).');
        }

        $names = [];
        foreach ([...$email->getTo(), ...$email->getCc(), ...$email->getBcc()] as $address) {
            $names[\strtolower($address->getAddress())] = $address->getName();
        }
        $recipients = [];
        $addresses = $envelope?->getRecipients() ?? [...$email->getTo(), ...$email->getCc(), ...$email->getBcc()];
        foreach ($addresses as $address) {
            $recipient = ['email' => $address->getAddress()];
            $name = $address->getName() !== '' ? $address->getName() : ($names[\strtolower($address->getAddress())] ?? '');
            if ($name !== '') {
                $recipient['substitutions'] = ['to_name' => $name];
            }
            $recipients[] = $recipient;
        }

        $message = [
            'recipients' => $recipients,
            'subject' => (string)$email->getSubject(),
            'from_email' => $from->getAddress(),
        ];
        if ($from->getName() !== '') {
            $message['from_name'] = $from->getName();
        }
        $replyTo = $email->getReplyTo()[0] ?? null;
        if ($replyTo !== null) {
            $message['reply_to'] = $replyTo->getAddress();
            if ($replyTo->getName() !== '') {
                $message['reply_to_name'] = $replyTo->getName();
            }
        }

        $body = \array_filter([
            'html' => self::text($email->getHtmlBody()),
            'plaintext' => self::text($email->getTextBody()),
        ], static fn (?string $part): bool => $part !== null);
        if ($body !== []) {
            $message['body'] = $body;
        }

        $headers = [];
        if ($email->getCc() !== []) {
            $headers['To'] = self::addresses($email->getTo());
            $headers['CC'] = self::addresses($email->getCc());
        }
        foreach ($email->getHeaders()->all() as $header) {
            $name = $header->getName();
            if ($header instanceof TagHeader) {
                $message['tags'][] = $header->getValue();
            } elseif ($header instanceof MetadataHeader) {
                $message['global_metadata'][$header->getKey()] = $header->getValue();
            } elseif (\str_starts_with(\strtolower($name), 'x-') || \in_array(\strtolower($name), \array_map('strtolower', self::PASSED_HEADERS), true)) {
                $headers[$name] = $header->getBodyAsString();
            }
        }
        if ($headers !== []) {
            $message['headers'] = $headers;
        }

        foreach ($email->getAttachments() as $part) {
            $item = self::attachment($part);
            if ($item['inline']) {
                $message['inline_attachments'][] = $item['data'];
            } else {
                $message['attachments'][] = $item['data'];
            }
        }

        return $message;
    }

    /**
     * @return array{inline: bool, data: array{type: string, name: string, content: string}}
     */
    private static function attachment(DataPart $part): array
    {
        $headers = $part->getPreparedHeaders();
        $inline = $headers->getHeaderBody('Content-Disposition') === 'inline';
        $filename = (string)$headers->getHeaderParameter('Content-Disposition', 'filename');

        return [
            'inline' => $inline,
            'data' => [
                'type' => $part->getMediaType() . '/' . $part->getMediaSubtype(),
                'name' => $inline && $part->hasContentId() ? $part->getContentId() : $filename,
                'content' => \base64_encode($part->getBody()),
            ],
        ];
    }

    /**
     * @param resource|string|null $part
     */
    private static function text(mixed $part): ?string
    {
        if (\is_resource($part)) {
            $part = \stream_get_contents($part);
        }

        return \is_string($part) ? $part : null;
    }

    /**
     * @param list<Address> $addresses
     */
    private static function addresses(array $addresses): string
    {
        return \implode(', ', \array_map(static fn (Address $address): string => $address->toString(), $addresses));
    }
}
