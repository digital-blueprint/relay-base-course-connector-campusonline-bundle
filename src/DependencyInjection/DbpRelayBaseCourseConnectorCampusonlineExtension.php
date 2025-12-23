<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\DependencyInjection;

use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\EventSubscriber\CourseEventSubscriber;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service\CourseProvider;
use Dbp\Relay\CoreBundle\Doctrine\DoctrineConfiguration;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;

class DbpRelayBaseCourseConnectorCampusonlineExtension extends ConfigurableExtension implements PrependExtensionInterface
{
    public const ENTITY_MANAGER_ID = 'dbp_relay_base_course_connector_campusonline_bundle';

    public function loadInternal(array $mergedConfig, ContainerBuilder $container): void
    {
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__.'/../Resources/config')
        );
        $loader->load('services.yaml');

        $courseProviderDefinition = $container->getDefinition(CourseProvider::class);
        $courseProviderDefinition->addMethodCall('setConfig', [$mergedConfig]);

        $courseEventSubscriberDefinition = $container->getDefinition(CourseEventSubscriber::class);
        $courseEventSubscriberDefinition->addMethodCall('setConfig', [$mergedConfig]);
    }

    public function prepend(ContainerBuilder $container): void
    {
        $configs = $container->getExtensionConfig($this->getAlias());
        $config = $this->processConfiguration(new Configuration(), $configs);

        DoctrineConfiguration::prependEntityManagerConfig($container, self::ENTITY_MANAGER_ID,
            $config[Configuration::DATABASE_URL],
            __DIR__.'/../Entity',
            'Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Entity');
        DoctrineConfiguration::prependMigrationsConfig($container,
            __DIR__.'/../Migrations',
            'Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Migrations');
    }
}
