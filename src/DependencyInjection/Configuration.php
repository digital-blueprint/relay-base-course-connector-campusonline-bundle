<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\DependencyInjection;

use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Cron\CacheRefreshCronJob;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\EventSubscriber\CourseEventSubscriber;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public const ROOT_NODE = 'dbp_relay_base_course_connector_campusonline';
    public const DATABASE_URL = 'database_url';
    public const NUM_SEMESTERS_TO_PROVIDE = 'num_semesters_to_provide';
    public const CAMPUS_ONLINE_NODE = 'campus_online';
    public const BASE_URL_NODE = 'base_url';
    public const CLIENT_ID_NODE = 'client_id';
    public const CLIENT_SECRET_NODE = 'client_secret';
    public const EVENT_TIME_ZONE_NODE = 'event_time_zone';
    public const CACHE_REFRESH_INTERVAL_NODE = 'cache_refresh_interval';

    private const DATABASE_URL_DEFAULT = 'sqlite:///%kernel.project_dir%/var/courses_cache.db';
    private const NUN_SEMESTERS_TO_PROVIDE_DEFAULT = 10;

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ROOT_NODE);
        $treeBuilder->getRootNode()
            ->children()
                ->scalarNode(self::DATABASE_URL)
                    ->defaultValue(self::DATABASE_URL_DEFAULT)
                ->end()
                ->scalarNode(self::NUM_SEMESTERS_TO_PROVIDE)
                    ->defaultValue(self::NUN_SEMESTERS_TO_PROVIDE_DEFAULT)
                ->end()
                ->arrayNode(self::CAMPUS_ONLINE_NODE)
                    ->children()
                        ->scalarNode(self::BASE_URL_NODE)
                            ->isRequired()
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode(self::CLIENT_ID_NODE)
                            ->isRequired()
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode(self::CLIENT_SECRET_NODE)
                            ->isRequired()
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode(self::EVENT_TIME_ZONE_NODE)
                            ->info('The time zone of the events, used for timestamp conversion')
                            ->defaultValue('Europe/Vienna')
                            ->example('Europe/Vienna')
                        ->end()
                    ->end()
                ->end()
                ->scalarNode(self::CACHE_REFRESH_INTERVAL_NODE)
                    ->defaultValue(CacheRefreshCronJob::DEFAULT_INTERVAL)
                ->end()
                ->append(CourseEventSubscriber::getLocalDataMappingConfigNodeDefinition())
            ->end();

        return $treeBuilder;
    }
}
