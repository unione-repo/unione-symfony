<?php

declare(strict_types=1);

namespace UniOne\Wrapper\Mailer;

use UniOne\Client\ApiClient;
use UniOne\Client\Configuration;
use UniOne\Wrapper\ClientFactory;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\Exception\UnsupportedSchemeException;
use Symfony\Component\Mailer\Transport\AbstractTransportFactory;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * MAILER_DSN:
 * - unione+api://default             the API client of the bundle (unione.api_key, unione.host);
 * - unione+api://API_KEY@default     own API key, default host;
 * - unione+api://API_KEY@us1.unione.io  own API key and host (datacenter).
 */
final class UniOneTransportFactory extends AbstractTransportFactory
{
    public function __construct(
        private readonly ApiClient $apiClient,
        ?EventDispatcherInterface $dispatcher = null,
        ?LoggerInterface $logger = null,
    ) {
        parent::__construct($dispatcher, null, $logger);
    }

    public function create(Dsn $dsn): TransportInterface
    {
        if (!\in_array($dsn->getScheme(), $this->getSupportedSchemes(), true)) {
            throw new UnsupportedSchemeException($dsn, 'unione', $this->getSupportedSchemes());
        }

        $client = $this->apiClient;
        $key = $dsn->getUser();
        if (null !== $key && '' !== $key) {
            $host = $dsn->getHost();
            $baseUrl = 'default' === $host ? Configuration::DEFAULT_BASE_URL : Configuration::baseUrlFor($host . (null !== $dsn->getPort() ? ':' . $dsn->getPort() : ''));
            $client = new ApiClient(ClientFactory::configuration($key, $baseUrl, $dsn->getOption('timeout')));
        }

        return new UniOneTransport($client, $this->dispatcher, $this->logger);
    }

    protected function getSupportedSchemes(): array
    {
        return ['unione+api', 'unione'];
    }
}
