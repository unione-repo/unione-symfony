<?php

declare(strict_types=1);

namespace UniOne\Wrapper\Tests;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use UniOne\Api\Webhook\Model\WebhookCallback;
use UniOne\Client\ApiClient;
use UniOne\Client\ApiException;
use UniOne\Client\Configuration;
use UniOne\UniOneClient;
use UniOne\Wrapper\ClientFactory;
use UniOne\Wrapper\Event\WebhookEventReceivedEvent;
use UniOne\Wrapper\Event\WebhookReceivedEvent;
use UniOne\Wrapper\Mailer\UnrecoverableTransportException;
use UniOne\Wrapper\Webhook\WebhookSignature;

/**
 * The bundle in a kernel of FrameworkBundle (TestKernel). The API client services send to FakeTransport: no request
 * leaves the machine.
 */
final class BundleTest extends KernelTestCase
{
    /** Base URL of the tests: a local address, never the default API host. */
    private const BASE_URL = 'http://127.0.0.1:9/api/v1';

    /** A webhook notification of the API documentation; WebhookSignature::sign() replaces the auth. */
    private const WEBHOOK = [
        'auth' => '00000000000000000000000000000000',
        'events_by_user' => [
            [
                'user_id' => 1,
                'project_id' => '6432890213745872',
                'events' => [
                    [
                        'event_name' => 'transactional_email_status',
                        'event_data' => [
                            'job_id' => '1a3Q2V-0000OZ-S0',
                            'email' => 'user@example.com',
                            'status' => 'delivered',
                            'event_time' => '2015-11-30 15:09:42',
                        ],
                    ],
                    [
                        'event_name' => 'transactional_spam_block',
                        'event_data' => [
                            'block_time' => '2015-11-30 15:09:42',
                            'block_type' => 'one_smtp',
                            'domain' => 'example.com',
                            'SMTP_blocks_count' => 1,
                            'domain_status' => 'blocked',
                        ],
                    ],
                ],
            ],
        ],
    ];

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();
        (new Filesystem())->remove(TestKernel::tempDir());
    }

    protected static function createKernel(array $options = []): KernelInterface
    {
        $config = \array_key_exists('config', $options)
            ? $options['config']
            : ['api_key' => 'test-key', 'host' => self::BASE_URL];

        return new TestKernel($config, $options['webhook_route'] ?? true);
    }

    public function testServicesFromConfiguration(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $client = $container->get(UniOneClient::class);
        $this->assertInstanceOf(UniOneClient::class, $client);
        $this->assertSame($client, $container->get('unione.client'));
        $config = $container->get('unione.configuration');
        $this->assertSame('test-key', $config->getApiKey());
        $this->assertSame(self::BASE_URL, $config->getBaseUrl());
        $this->assertSame(ClientFactory::PLATFORM . ',' . ApiClient::PLATFORM, $config->getPlatform());
        $this->assertSame('symfony.2.3.2', ClientFactory::PLATFORM);
    }

    public function testSettingsFromEnvironment(): void
    {
        self::setEnv('UNIONE_API_KEY', 'env-key');
        self::setEnv('UNIONE_HOST', self::BASE_URL);
        try {
            self::bootKernel(['config' => null]);
            $config = self::getContainer()->get('unione.configuration');
        } finally {
            self::setEnv('UNIONE_API_KEY', null);
            self::setEnv('UNIONE_HOST', null);
        }

        $this->assertInstanceOf(Configuration::class, $config);
        $this->assertSame('env-key', $config->getApiKey());
        $this->assertSame(self::BASE_URL, $config->getBaseUrl());
    }

    public function testMissingApiKey(): void
    {
        self::bootKernel(['config' => ['api_key' => '']]);

        $this->expectException(\InvalidArgumentException::class);
        self::getContainer()->get('unione.configuration');
    }

    public function testMail(): void
    {
        self::bootKernel();
        $fake = self::getContainer()->get(FakeTransport::class);
        $fake->add(FakeTransport::json(200, [
            'status' => 'success',
            'job_id' => 'job-1',
            'emails' => ['user@example.com'],
        ]));

        $sent = $this->send();

        $this->assertInstanceOf(SentMessage::class, $sent);
        $this->assertSame('job-1', $sent->getMessageId());
        $this->assertCount(1, $fake->requests);
        $request = $fake->requests[0];
        $query = \http_build_query(['platform' => ClientFactory::PLATFORM . ',' . ApiClient::PLATFORM]);
        $this->assertSame(self::BASE_URL . '/email/send.json?' . $query, $request['url']);
        $this->assertSame('test-key', $request['headers']['X-API-KEY']);
        $message = \json_decode($request['body'], true)['message'];
        $recipient = ['email' => 'user@example.com', 'substitutions' => ['to_name' => 'User']];
        $this->assertSame([$recipient], $message['recipients']);
        $this->assertSame('shop@example.com', $message['from_email']);
        $this->assertSame('Shop', $message['from_name']);
        $this->assertSame('Test', $message['subject']);
        $this->assertSame('Hello', $message['body']['plaintext']);
    }

    public function testMailApiError(): void
    {
        self::bootKernel();
        self::getContainer()->get(FakeTransport::class)
            ->add(FakeTransport::json(400, ['status' => 'error', 'message' => 'Unknown recipient', 'code' => 204]));

        $e = $this->sendError();

        // not retryable: symfony/messenger must not repeat it
        $this->assertInstanceOf(UnrecoverableTransportException::class, $e);
        $this->assertStringContainsString('email/send: Unknown recipient (API error 204, HTTP 400)', $e->getMessage());
        $previous = $e->getPrevious();
        $this->assertInstanceOf(ApiException::class, $previous);
        $this->assertSame(204, $previous->getErrorCode());
        $this->assertFalse($previous->isRetryable());
    }

    public function testMailRetryableError(): void
    {
        self::bootKernel();
        self::getContainer()->get(FakeTransport::class)
            ->add(FakeTransport::json(503, ['status' => 'error', 'message' => 'Service unavailable', 'code' => 0]));

        $e = $this->sendError();

        $this->assertNotInstanceOf(UnrecoverableTransportException::class, $e);
        $this->assertInstanceOf(ApiException::class, $e->getPrevious());
        $this->assertTrue($e->getPrevious()->isRetryable());
    }

    public function testWebhook(): void
    {
        $kernel = self::bootKernel();
        $received = [];
        $dispatcher = self::getContainer()->get('event_dispatcher');
        foreach ([WebhookReceivedEvent::class, WebhookEventReceivedEvent::class] as $event) {
            $dispatcher->addListener($event, static function (object $e) use (&$received): void {
                $received[] = $e;
            });
        }
        $body = self::signedWebhook('test-key');

        $this->assertSame(200, $kernel->handle(Request::create('/unione/webhook', 'GET'))->getStatusCode());
        $this->assertSame(200, self::post($kernel, $body));
        $this->assertSame(401, self::post($kernel, \str_replace('user@', 'evil@', $body)));
        $this->assertSame(401, self::post($kernel, self::signedWebhook('other-key')));

        $this->assertCount(3, $received);
        $this->assertInstanceOf(WebhookReceivedEvent::class, $received[0]);
        $this->assertInstanceOf(WebhookCallback::class, $received[0]->callback);
        $this->assertInstanceOf(WebhookEventReceivedEvent::class, $received[1]);
        $this->assertSame('user@example.com', $received[1]->event->eventData?->email);
    }

    public function testWebhookWithoutRoute(): void
    {
        $kernel = self::bootKernel(['webhook_route' => false]);

        $this->assertSame(404, self::post($kernel, self::signedWebhook('test-key')));
    }

    private static function post(KernelInterface $kernel, string $body): int
    {
        $request = Request::create(
            '/unione/webhook',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: $body,
        );

        return $kernel->handle($request)->getStatusCode();
    }

    private function send(): ?SentMessage
    {
        $email = (new Email())
            ->from(new Address('shop@example.com', 'Shop'))
            ->to(new Address('user@example.com', 'User'))
            ->subject('Test')
            ->text('Hello');

        return self::getContainer()->get('test.mailer_transport')->send($email);
    }

    private function sendError(): TransportException
    {
        try {
            $this->send();
        } catch (TransportException $e) {
            return $e;
        }
        $this->fail('TransportException expected');
    }

    private static function signedWebhook(string $key): string
    {
        return WebhookSignature::sign(\json_encode(self::WEBHOOK, \JSON_THROW_ON_ERROR), $key);
    }

    private static function setEnv(string $name, ?string $value): void
    {
        if (null === $value) {
            \putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);

            return;
        }
        \putenv("$name=$value");
        $_ENV[$name] = $_SERVER[$name] = $value;
    }
}
