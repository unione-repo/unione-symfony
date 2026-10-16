<?php

declare(strict_types=1);

namespace UniOne\Wrapper\Routing;

use UniOne\Wrapper\Controller\WebhookController;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/**
 * Route "unione_webhook" at the configured unione.webhook.path:
 *
 * ```yaml
 * # config/routes/unione.yaml
 * unione_webhook:
 *     resource: .
 *     type: unione_webhook
 * ```
 */
final class WebhookRouteLoader extends Loader
{
    public function __construct(private readonly string $path)
    {
        parent::__construct();
    }

    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $routes = new RouteCollection();
        $routes->add('unione_webhook', new Route($this->path, ['_controller' => WebhookController::class], methods: ['GET', 'HEAD', 'POST']));

        return $routes;
    }

    public function supports(mixed $resource, ?string $type = null): bool
    {
        return 'unione_webhook' === $type;
    }
}
