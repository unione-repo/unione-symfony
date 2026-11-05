<?php

declare(strict_types=1);

namespace UniOne\Wrapper\Tests;

use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use UniOne\Wrapper\UniOneBundle;

/**
 * An application with the bundle: the API client services send to FakeTransport (a public service), the mailer DSN
 * is unione+api://default, the webhook route is imported unless $webhookRoute is false.
 */
final class TestKernel extends Kernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed>|null $config settings of the bundle (unione:), null: the environment variables
     */
    public function __construct(private readonly ?array $config, private readonly bool $webhookRoute = true)
    {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new UniOneBundle();
    }

    public function getProjectDir(): string
    {
        return __DIR__;
    }

    public function getCacheDir(): string
    {
        return self::tempDir() . '/' . \md5(\serialize([$this->config, $this->webhookRoute]));
    }

    public function getLogDir(): string
    {
        return self::tempDir() . '/log';
    }

    /**
     * Cache of the kernels of this run (BundleTest removes it): no stale container of an earlier run.
     */
    public static function tempDir(): string
    {
        return \sys_get_temp_dir() . '/unione-bundle-tests-' . \getmypid();
    }

    public function process(ContainerBuilder $container): void
    {
        foreach (['unione.api_client', 'unione.client'] as $id) {
            $container->getDefinition($id)->setArgument(1, new Reference(FakeTransport::class));
        }
        $container->getDefinition('unione.configuration')->setPublic(true);
        $container->register('logger', NullLogger::class);  // the 404/401 of the tests: no log lines in the output
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_method_override' => false,
            'handle_all_throwables' => true,
            'php_errors' => ['log' => true],
            'messenger' => ['enabled' => false],
            'mailer' => ['dsn' => 'unione+api://default'],
        ]);
        if (null !== $this->config) {
            $container->extension('unione', $this->config);
        }
        $services = $container->services();
        $services->set(FakeTransport::class)->public();
        $services->alias('test.mailer_transport', 'mailer.default_transport')->public();
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        if ($this->webhookRoute) {
            $routes->import('.', 'unione_webhook');
        }
    }
}
