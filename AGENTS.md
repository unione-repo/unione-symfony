# AGENTS.md

UniOne Symfony — Symfony integration of the UniOne PHP SDK (`unione-repo/unione-php-sdk`) for the UniOne transactional email Web API (Web API 1.90). How to integrate UniOne into a Symfony application comes first; the last section is about changing this package.

## Integrating UniOne with this package

Symfony integration of the [UniOne PHP SDK](https://github.com/unione-repo/unione-php-sdk) (`unione-repo/unione-php-sdk`): the API client in the service container, a webhook endpoint with signature check, a Symfony Mailer transport that sends through `email/send`. Full guide: [README.md](README.md).

### Install

```bash
composer require unione-repo/unione-symfony-bundle
```

Requirements: PHP 8.1+, Symfony 6.4 / 7.x.

### Configuration

The API key goes to `UNIONE_API_KEY`.

```yaml
# config/bundles.php: UniOne\Wrapper\UniOneBundle::class => ['all' => true]

# .env
#   UNIONE_API_KEY=YOUR_API_KEY
#   UNIONE_HOST=us1.unione.io
#   MAILER_DSN=unione+api://default

# config/packages/unione.yaml (optional, these are the defaults)
unione:
    api_key: '%env(UNIONE_API_KEY)%'
    host: '%env(default::UNIONE_HOST)%'
    webhook:
        path: '/unione/webhook'

# config/routes/unione.yaml
unione_webhook:
    resource: .
    type: unione_webhook
```

`us1.unione.io` in the example is for an account on another datacenter; without the host setting the package uses `api.unione.io`.

| Option | Environment variable | Default | Description |
|---|---|---|---|
| `unione.api_key` | `UNIONE_API_KEY` |  | User or project API key (required). |
| `unione.host` | `UNIONE_HOST` | `api.unione.io` | API host (datacenter: `api.unione.io`, `eu1.unione.io`, `us1.unione.io`) or a full base URL. |
| `unione.timeout` | `UNIONE_TIMEOUT` | `30` | Request timeout, seconds. |
| `unione.webhook.key` | `UNIONE_WEBHOOK_KEY` |  | API key the webhooks are signed with (the key of the user/project of the webhook), if it differs from the API key. |
| `unione.webhook.path` | `UNIONE_WEBHOOK_PATH` | `/unione/webhook` | Path of the webhook endpoint. |

### Sending mail

Messages of the Symfony Mailer go through [email/send](https://docs.unione.io/en/web-api-ref#email-send): from, to/cc/bcc, reply-to, subject, HTML and text parts, attachments, inline images and `X-*` headers.

```php
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mime\Email;

$email = (new Email())
    ->from('shop@example.com')
    ->to('user@example.com')
    ->subject('Order shipped')
    ->html('<p>Your order is on its way</p>');
$email->getHeaders()->add(new TagHeader('orders'))->add(new MetadataHeader('order_id', '42'));

$mailer->send($email);
```

### API client

The client calls [email/send](https://docs.unione.io/en/web-api-ref#email-send) with all of its options: templates, substitutions, metadata, tracking. Fields of the request and other examples: [PHP SDK docs](https://github.com/unione-repo/unione-php-sdk/blob/HEAD/docs/Email.md#send).

```php
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use UniOne\Api\Email\Model\EmailSendRequest;
use UniOne\Api\Email\Model\MessageObject;
use UniOne\Api\Email\Model\MessageObjectRecipientsItem as Recipient;
use UniOne\Common\Model\BodyObject as Body;
use UniOne\UniOneClient;

final class EmailController extends AbstractController
{
    #[Route('/send', methods: ['POST'])]
    public function send(UniOneClient $client): JsonResponse
    {
        $response = $client->email()->send(new EmailSendRequest(new MessageObject(
            recipients: [Recipient::fromAddress('Alex <alex@example.com>')],
            body: Body::html('<p>Hello, {{to_name}}! Your order has shipped.</p>'),
            subject: 'Your order',
            fromEmail: 'shop@example.com',
            fromName: 'Shop',
        )));

        return $this->json(['job_id' => $response->jobId]);
    }
}
```

### Webhooks

The endpoint answers `GET` with 200 OK (the URL check of [webhook/set](https://docs.unione.io/en/web-api-ref#webhook-set)) and accepts the `POST` notifications: it checks the `auth` field (MD5 of the request body in which the `auth` value is replaced by the API key), answers 401 to a wrong signature, and passes the notification and each of its events to the application.

```php
use UniOne\Wrapper\Event\WebhookEventReceivedEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class EmailStatusListener
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function __invoke(WebhookEventReceivedEvent $e): void
    {
        $data = $e->event->eventData;
        $this->logger->info('UniOne event', ['name' => ($n = $e->event->eventName) instanceof \BackedEnum ? $n->value : $n, 'email' => $data?->email,
            'status' => ($s = $data?->status) instanceof \BackedEnum ? $s->value : $s]);
    }
}
```

Register the URL of the endpoint with `webhook/set`, e.g. `https://example.com/unione/webhook`.

> **Answer 200 within 3 seconds** and process the events in the background (a queue): otherwise UniOne repeats the request every 10 minutes for 24 hours (`retry_count` grows), and a handler that failed every request for at least 10 events of a day is turned inactive. The handler must take at least **5 parallel requests of up to 100 events each**; deduplicate, a repeat delivers the same events again.

### Rules for generated code

- Server-side only. Read the API key from `UNIONE_API_KEY`; never put it in browser or mobile code, never commit it.
- Base URL `https://api.unione.io/en/transactional/api/v1` (an account registered on another host uses that one: `eu1.unione.io`, `us1.unione.io`); every method is POST with a JSON body; auth header `X-API-KEY`. The package does all of it.
- First email without DNS: `domain/get-sandbox-domain` → `confirmed-email/create` (the user clicks the link within 48 hours) → `email/send` from `noreply@<sandbox-domain>` to the confirmed address. Account API key only; up to 10 confirmed addresses, 100 emails a day. The domain is deleted 31 days after it was created: the next `domain/get-sandbox-domain` call creates a new one with another name, so read the name from the response, never hardcode it.
- Never ship a sandbox domain as the production sender (it is not in `domain/list`); read `from_email` from config. Production needs a verified domain: `domain/get-dns-records` → DNS records (verification TXT as is, DKIM TXT `v=DKIM1; k=rsa; p=<dkim>` on `<selector>._domainkey`, CNAME `_dmarc` → the `dmarc` field; SPF `include:spf.unione.io` is not in the response) → `domain/validate-verification-record`, `domain/validate-dkim` → `domain/list`.
- Set `message.idempotence_key` on retryable sends; retry network errors, `429` and `5xx` with backoff, not other errors unchanged. Up to 500 recipients and 10 MB per request with the attachments in base64 (a third larger than the files); always `body.html` or `body.plaintext`.
- Delivery events via webhook (`webhook/set`), not polling; the webhook endpoint of this package checks the `auth` signature (401 when wrong). Keep the listeners fast: answer 200 within 3 seconds and process the events from a queue, otherwise the request is repeated every 10 minutes for 24 hours; take at least 5 parallel requests of up to 100 events each; deduplicate.
- Keep the default unsubscribe and tracking behaviour; changes go through UniOne support.
- Do not invent methods or fields. Check the [README](README.md), the [API reference](https://docs.unione.io/en/web-api-ref) or [llms.txt](https://docs.unione.io/llms.txt).

### Reference

- [UniOne PHP SDK](https://github.com/unione-repo/unione-php-sdk): every API method, models, errors ([AGENTS.md](https://github.com/unione-repo/unione-php-sdk/blob/HEAD/AGENTS.md))
- [API reference](https://docs.unione.io/en/web-api-ref)
- [Full API reference for LLMs](https://docs.unione.io/llms-full.txt)

## Working on this repository

- **Generated code.** The whole package is generated by the UniOne team from the UniOne Web API OpenAPI spec (Web API 1.90): sources, docs, README.md and this file. Do not hand-edit it: the next release replaces the content of this repository. Report problems and requests in the issues of this repository (https://github.com/unione-repo/unione-symfony).
- **Build and check:**

  ```bash
  composer validate
  composer install
  ```

- **Source label.** Every request carries the query parameter `platform=symfony.2.2.4,php-sdk.2.2.4` (this package, then the SDK it is built on).
- **Tests.** Before a release the generator installs the package into a test app of Symfony and checks the client, the webhook endpoint and the mailer against a mock of the API: no real API key, no real sends.
- **Release.** The version (2.2.4 in composer.json) and the tag `v2.2.4` come from the generator; README.md states the Web API version the release was generated from.
- **Secrets.** Never commit API keys; the examples read `UNIONE_API_KEY` from the environment.
