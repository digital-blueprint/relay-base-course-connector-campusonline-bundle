<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\DependencyInjection;

use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\EventSubscriber\CourseEventSubscriber;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public const ROOT_NODE = 'dbp_relay_base_course_connector_campusonline';
    public const DATABASE_URL = 'database_url';
    public const CAMPUS_ONLINE_NODE = 'campus_online';
    public const LEGACY_NODE = 'legacy';
    public const BASE_URL_NODE = 'base_url';
    public const CLIENT_ID_NODE = 'client_id';
    public const CLIENT_SECRET_NODE = 'client_secret';

    private const DATABASE_URL_DEFAULT = 'sqlite:///%kernel.project_dir%/var/courses_cache.db';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ROOT_NODE);
        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode(self::DATABASE_URL)
                    ->defaultValue(self::DATABASE_URL_DEFAULT)
                ->end()
                ->arrayNode(self::CAMPUS_ONLINE_NODE)
                    ->children()
                        ->booleanNode(self::LEGACY_NODE)
                            ->defaultTrue()
                        ->end()
                        ->scalarNode('api_url')->end()
                        ->scalarNode('api_token')->end()
                        ->scalarNode('org_root_id')->end()
                        ->scalarNode(self::BASE_URL_NODE)->end()
                        ->scalarNode(self::CLIENT_ID_NODE)->end()
                        ->scalarNode(self::CLIENT_SECRET_NODE)->end()
                    ->end()
                ->end()
                ->append(CourseEventSubscriber::getLocalDataMappingConfigNodeDefinition())
            ->end();

        return $treeBuilder;
    }
}
