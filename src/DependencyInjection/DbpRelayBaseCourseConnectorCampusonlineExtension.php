<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\DependencyInjection;

use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\EventSubscriber\CourseEventSubscriber;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\HttpKernel\DependencyInjection\ConfigurableExtension;

class DbpRelayBaseCourseConnectorCampusonlineExtension extends ConfigurableExtension
{
    public function loadInternal(array $mergedConfig, ContainerBuilder $container)
    {
        $loader = new YamlFileLoader(
            $container,
            new FileLocator(__DIR__.'/../Resources/config')
        );
        $loader->load('services.yaml');

        $courseApi = $container->getDefinition('Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service\CourseApi');
        $courseApi->addMethodCall('setConfig', [$mergedConfig['campus_online'] ?? []]);

        $courseApi = $container->getDefinition(CourseEventSubscriber::class);
        $courseApi->addMethodCall('setConfig', [$mergedConfig]);
    }
}
