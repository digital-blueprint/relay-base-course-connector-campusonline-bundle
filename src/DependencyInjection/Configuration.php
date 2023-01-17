<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\DependencyInjection;

use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\EventSubscriber\CoursePostEventSubscriber;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('dbp_relay_base_course_connector_campusonline');
        $treeBuilder->getRootNode()
            ->children()
                ->arrayNode('campus_online')
                    ->children()
                        ->scalarNode('api_url')->end()
                        ->scalarNode('api_token')->end()
                        ->scalarNode('org_root_id')->end()
                    ->end()
                ->end()
                ->append(CoursePostEventSubscriber::getLocalDataMappingConfigNodeDefinition())
            ->end();

        return $treeBuilder;
    }
}
