<?php

declare(strict_types=1);

namespace Survos\AirNowBundle\Tests;

use PHPUnit\Framework\TestCase;
use Survos\AirNowBundle\Service\AirNowClient;
use Survos\AirNowBundle\SurvosAirNowBundle;
use Survos\FetchBundle\SurvosFetchBundle;
use Survos\Kit\SurvosKitBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;

final class BundleTest extends TestCase
{
    public function testBundleBootsAndClientIsAutowired(): void
    {
        $kernel = new AirNowTestKernel('test', true);
        try {
            $kernel->boot();
            $client = $kernel->getContainer()->get('test.client');
            self::assertInstanceOf(AirNowClient::class, $client);
            self::assertSame(['00501'], $client->defaultZipCodes);
        } finally {
            $kernel->shutdown();
        }
    }
}

final class AirNowTestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new SurvosKitBundle();
        yield new SurvosFetchBundle();
        yield new SurvosAirNowBundle();
    }

    public function getCacheDir(): string
    {
        return dirname(__DIR__).'/var/cache/test';
    }

    public function getLogDir(): string
    {
        return dirname(__DIR__).'/var/log';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', ['secret' => 'test', 'http_client' => []]);
        $container->extension('survos_airnow', ['api_key' => 'test', 'default_zip_codes' => ['00501']]);
        $container->services()->alias('test.client', AirNowClient::class)->public();
    }
}
