# UniOne Symfony

[![Symfony](https://img.shields.io/packagist/v/unione-repo/unione-symfony-bundle)](https://packagist.org/packages/unione-repo/unione-symfony-bundle) [![License: MIT](https://img.shields.io/badge/license-MIT-blue)](LICENSE)

Symfony integration of the [UniOne PHP SDK](https://github.com/unione-repo/unione-php-sdk) (`unione-repo/unione-php-sdk`): the API client in the service container, a webhook endpoint with signature check, a Symfony Mailer transport that sends through `email/send`.

[API reference](https://docs.unione.io/en/web-api-ref) · Docs for LLMs: [llms.txt](https://docs.unione.io/llms.txt)

## Install

```bash
composer require unione-repo/unione-symfony-bundle
```

Requirements: PHP 8.1+, Symfony 6.4 / 7.x.

## Configuration

Get an API key in the [control panel](https://cp.unione.io/en/settings/security/api) and keep it in the environment, never in code:

```bash
export UNIONE_API_KEY="your-api-key"
```

Settings are read from the environment by default:

```yaml
# Symfony Flex creates these with the recipe once it is in symfony/recipes-contrib and the project allows contrib
# recipes (composer config extra.symfony.allow-contrib true); otherwise create them by hand:
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

`MAILER_DSN=unione+api://default` sends with the client of the bundle; `unione+api://API_KEY@default` or `unione+api://API_KEY@us1.unione.io` - with another key/host.

## Sending mail

Messages of the Symfony Mailer go through [email/send](https://docs.unione.io/en/web-api-ref#email-send): from, to/cc/bcc, reply-to, subject, HTML and text parts, attachments, inline images and `X-*` headers.

```php
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;
use Symfony\Component\Mime\Email;

$email = (new Email())
    ->from('shop@example.com')
    ->to('user@example.com')
    ->subject('Order shipped')
    ->html('<p>Your order is on its way</p>');
$email->getHeaders()->add(new TagHeader('orders'))->add(new MetadataHeader('order_id', '42'));

try {
    $mailer->send($email);
} catch (TransportExceptionInterface $e) {
    $error = $e->getPrevious();  // the ApiException of the SDK: getKind(), isRetryable(), getHint()
}
```

No verified domain yet? Send from the [test sandbox domain](#no-domain-yet-the-test-sandbox-domain).

## Webhooks

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

## API client

Every section of the API is available on the client, see the [SDK docs](https://github.com/unione-repo/unione-php-sdk).

```php
use UniOne\UniOneClient;

final class UsageController
{
    public function __construct(private readonly UniOneClient $client)
    {
    }

    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['emails_sent' => $this->client->system()->info()->accounting?->emailsSent]);
    }
}
```

### Sending through the API

Besides the Symfony Mailer, mail can be sent through the API client directly. The client calls [email/send](https://docs.unione.io/en/web-api-ref#email-send) with all of its options: templates, substitutions, metadata, tracking. Fields of the request and other examples: [PHP SDK docs](https://github.com/unione-repo/unione-php-sdk/blob/HEAD/docs/Email.md#send).

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

### Errors

A failed send throws the `TransportException` of Symfony Mailer (`UnrecoverableTransportException` when a repeat can not succeed and symfony/messenger is installed, so the messenger does not retry it); its `getPrevious()` is the SDK `ApiException`: `getKind()`, `isRetryable()`, `getHint()`, `getApiMessage()`. The API client throws the `ApiException` itself.

## No domain yet: the test sandbox domain

Until your domain is verified, send from the **sandbox domain**: a test domain every account has, already verified, with DKIM and SPF set. It delivers only to addresses you confirm (the confirmation link comes by email, valid 48 hours), up to 100 emails a day. The sandbox domain is deleted 31 days after it was created, but you can get it again: the next `domain/get-sandbox-domain` call creates a new one (with a new name, so read it from the call, do not hardcode it).

The examples use the API client of the package (see the configuration above).

```php
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use UniOne\Api\ConfirmedEmail\Model\ConfirmedEmailRequest;
use UniOne\Api\Email\Model\EmailSendRequest;
use UniOne\Api\Email\Model\MessageObject;
use UniOne\Api\Email\Model\MessageObjectRecipientsItem as Recipient;
use UniOne\Common\Model\BodyObject as Body;
use UniOne\UniOneClient;

final class SandboxController extends AbstractController
{
    #[Route('/sandbox', methods: ['POST'])]
    public function sandbox(UniOneClient $client): JsonResponse
    {
        // 1. Your sandbox domain: created on the first call, the same one afterwards
        $domain = $client->domain()->getSandboxDomain()->domain;

        // 2. Confirm your inbox: UniOne sends a link, click it within 48 hours
        $client->confirmedEmail()->create(new ConfirmedEmailRequest(email: 'you@example.com'));

        // 3. Send from any address on the sandbox domain to the confirmed one
        $client->email()->send(new EmailSendRequest(new MessageObject(
            recipients: [Recipient::fromAddress('you@example.com')],
            body: Body::html('<p>It works!</p>'),
            subject: 'Hello from UniOne',
            fromEmail: "noreply@{$domain}",
        )));

        return $this->json(['domain' => $domain]);
    }
}
```

<details>
<summary>Sandbox limits</summary>

- Sends only to confirmed addresses: up to 10 per account.
- Up to 100 emails a day.
- The sandbox domain expires after 31 days; call `domain/get-sandbox-domain` again to get a new one.
- Works only with the account API key, not a project key.
- Not listed in `domain/list`; the other `domain/*` methods do not apply to it.

The sandbox is for development and testing. Do not use a sandbox domain as the sender in production.

</details>

<details>
<summary>The same with curl</summary>

```bash
BASE=https://api.unione.io/en/transactional/api/v1

curl -s -X POST $BASE/domain/get-sandbox-domain.json \
  -H "Content-Type: application/json" -H "X-API-KEY: $UNIONE_API_KEY" -d '{}'

curl -s -X POST $BASE/confirmed-email/create.json \
  -H "Content-Type: application/json" -H "X-API-KEY: $UNIONE_API_KEY" \
  -d '{"email": "you@example.com"}'

curl -s -X POST $BASE/email/send.json \
  -H "Content-Type: application/json" -H "X-API-KEY: $UNIONE_API_KEY" \
  -d '{
        "message": {
          "recipients": [
            {
              "email": "you@example.com"
            }
          ],
          "from_email": "noreply@<sandbox-domain>",
          "subject": "Hello from UniOne",
          "body": {
            "html": "<p>It works!</p>"
          }
        }
      }'
```

</details>

## Going to production

**1. Verify your sending domain.** `domain/get-dns-records` returns the verification record, the DKIM key and the DMARC target; add the records at your DNS provider, then `domain/validate-verification-record` and `domain/validate-dkim`.

| Type | Host | Value | Where it comes from |
|---|---|---|---|
| TXT | `@` | `unione-validate-hash=…` | `verification-record` of the response, as is |
| TXT | `<selector>._domainkey` (the selector is on the domain page of the control panel, e.g. `us`) | `v=DKIM1; k=rsa; p=<dkim>` | `dkim` of the response after `v=DKIM1; k=rsa; p=` |
| TXT | `@` | `v=spf1 include:spf.unione.io ~all` | not in the response; add the include to your SPF record if you have one |
| CNAME | `_dmarc` | `<domain>.dmarc.…` | `dmarc` of the response (UniOne publishes the DMARC policy); keep your own DMARC TXT record instead if the domain has one |

**2. Make sending reliable.**

| | |
|---|---|
| Retries | `429` and `5xx` with backoff and the same `idempotence_key`; fix other errors instead of retrying |
| Limits | 500 recipients and 10 MB per request, attachments included: base64 makes a file about a third larger |
| Body | always `html` or `plaintext` (a template can supply it) |
| Delivery status | a webhook, not polling |
| Queue | send from a background job, not inside the web request |
| Config | `from_email` from config, so the sandbox → production switch needs no code change |
| Defaults | keep the unsubscribe footer and tracking; changing them needs UniOne support |

## Help

- [Integration guides](https://docs.unione.io/en/integrations)
- [PHP SDK](https://github.com/unione-repo/unione-php-sdk)
- AI coding agents: [llms.txt](https://docs.unione.io/llms.txt), the [MCP server](https://unione.io/en/agents), skills: `npx skills add https://github.com/unione-repo/unione-skills.git`
- [Support](https://cp.unione.io/en/support) · [Issues](https://github.com/unione-repo/unione-symfony/issues)
- Contributing: [AGENTS.md](AGENTS.md) — the package is generated from the OpenAPI spec.
