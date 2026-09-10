<?php

declare(strict_types=1);

namespace Survos\AirNowBundle;

use Psr\Log\NullLogger;
use Survos\AirNowBundle\Service\AirNowClient;
use Survos\FetchBundle\Service\PersistentFetcher;
use Survos\FetchBundle\SurvosFetchBundle;
use Survos\Kit\AbstractSurvosBundle;
use Survos\Kit\SurvosKitBundle;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Kernel\RequiredBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

#[RequiredBundle(SurvosKitBundle::class)]
#[RequiredBundle(SurvosFetchBundle::class)]
// Symfony\Component\HttpKernel\Bundle\Bundle <-- Flex auto-registration marker (see Survos\Kit\AbstractSurvosBundle)
final class SurvosAirNowBundle extends AbstractSurvosBundle
{
    protected string $extensionAlias = 'survos_airnow';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()->children()
            ->stringNode('api_key')->defaultValue('')->end()
            ->arrayNode('default_zip_codes')->stringPrototype()->end()->defaultValue([])->end()
            ->integerNode('cache_ttl')->min(1)->defaultValue(3600)->end()
            ->floatNode('timeout')->min(0.1)->defaultValue(10.0)->end()
        ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        parent::loadExtension($config, $container, $builder);
        $services = $container->services()->defaults()->autowire()->autoconfigure();
        $services->set('survos_airnow.null_logger', NullLogger::class);
        // The shared retry logger prints full URLs, which contain AirNow's API key.
        $services->set('survos_airnow.fetcher', PersistentFetcher::class)
            ->arg('$cache', service('survos_fetch.sqlite_cache'))
            ->arg('$logger', service('survos_airnow.null_logger'));
        $services->set(AirNowClient::class)
            ->arg('$fetcher', service('survos_airnow.fetcher'))
            ->arg('$apiKey', $config['api_key'])
            ->arg('$defaultZipCodes', $config['default_zip_codes'])
            ->arg('$cacheTtl', $config['cache_ttl'])
            ->arg('$timeout', $config['timeout']);
    }
}
