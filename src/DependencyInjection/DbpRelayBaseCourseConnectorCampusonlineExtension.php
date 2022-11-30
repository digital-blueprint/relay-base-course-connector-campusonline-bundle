<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\DependencyInjection;

use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\EventSubscriber\CoursePostEventSubscriber;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
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

        $courseCache = $container->register('dbp_api.cache.course.campus_online', FilesystemAdapter::class);
        $courseCache->setArguments(['relay-base-course-connector-campusonline', 60, '%kernel.cache_dir%/dbp/relay-base-course-connector-campusonline']);
        $courseCache->setPublic(true);
        $courseCache->addTag('cache.pool');

        $courseApi = $container->getDefinition('Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service\CourseApi');
        $courseApi->addMethodCall('setCache', [$courseCache, 3600]);
        $courseApi->addMethodCall('setConfig', [$mergedConfig['campus_online'] ?? []]);

        $courseApi = $container->getDefinition(CoursePostEventSubscriber::class);
        $courseApi->addMethodCall('setConfig', [$mergedConfig]);
    }
}
