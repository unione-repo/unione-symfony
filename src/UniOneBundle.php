<?php

declare(strict_types=1);

namespace UniOne\Wrapper;

use UniOne\Client\ApiClient;
use UniOne\Client\Configuration;
use UniOne\UniOneClient;
use UniOne\Wrapper\Controller\WebhookController;
use UniOne\Wrapper\Mailer\UniOneTransportFactory;
use UniOne\Wrapper\Routing\WebhookRouteLoader;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * UniOne API: UniOneClient / ApiClient / Configuration services, the "unione+api" Symfony Mailer transport and the
 * webhook endpoint (route type "unione_webhook").
 */
final class UniOneBundle extends AbstractBundle
{
    protected string $extensionAlias = 'unione';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('api_key')->info('User or project API key.')->defaultValue('%env(UNIONE_API_KEY)%')->end()
                ->scalarNode('host')->info('API host (api.unione.io, eu1.unione.io, us1.unione.io) or a full base URL, empty: https://api.unione.io/en/transactional/api/v1.')->defaultValue('%env(default::UNIONE_HOST)%')->end()
                ->scalarNode('timeout')->info('Request timeout, seconds.')->defaultValue('%env(default::UNIONE_TIMEOUT)%')->end()
                ->arrayNode('webhook')->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('path')->info('Path of the webhook endpoint.')->defaultValue('/unione/webhook')->end()
                        ->scalarNode('key')->info('API key the notifications are signed with, empty: api_key.')->defaultValue('%env(default::UNIONE_WEBHOOK_KEY)%')->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * @param array{api_key: string, host: string|null, timeout: string|float|null, webhook: array{path: string, key: string|null}} $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $services = $container->services();

        $services->set('unione.configuration', Configuration::class)
            ->factory([ClientFactory::class, 'configuration'])
            ->args([$config['api_key'], $config['host'], $config['timeout']]);
        $services->alias(Configuration::class, 'unione.configuration');

        $services->set('unione.api_client', ApiClient::class)
            ->args([service('unione.configuration')]);
        $services->alias(ApiClient::class, 'unione.api_client');

        $services->set('unione.client', UniOneClient::class)
            ->args([service('unione.configuration')]);
        $services->alias(UniOneClient::class, 'unione.client')->public();

        $services->set('unione.mailer.transport_factory', UniOneTransportFactory::class)
            ->args([
                service('unione.api_client'),
                service('event_dispatcher')->nullOnInvalid(),
                service('logger')->nullOnInvalid(),
            ])
            ->tag('mailer.transport_factory');

        $services->set(WebhookController::class)
            ->args([
                $config['webhook']['key'],
                $config['api_key'],
                service('event_dispatcher'),
            ])
            ->tag('controller.service_arguments')
            ->public();

        $services->set('unione.webhook.route_loader', WebhookRouteLoader::class)
            ->args([$config['webhook']['path']])
            ->tag('routing.loader');
    }
}
