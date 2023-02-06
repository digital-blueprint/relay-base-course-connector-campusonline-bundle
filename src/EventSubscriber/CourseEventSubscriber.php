<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\EventSubscriber;

use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event\CoursePostEvent;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event\CoursePreEvent;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service\CourseProvider;
use Dbp\Relay\CoreBundle\LocalData\AbstractLocalDataEventSubscriber;
use Dbp\Relay\CoreBundle\LocalData\LocalDataPreEvent;

class CourseEventSubscriber extends AbstractLocalDataEventSubscriber
{
    protected static function getSubscribedEventNames(): array
    {
        return [
            CoursePreEvent::class,
            CoursePostEvent::class,
        ];
    }

    protected function onPreEvent(LocalDataPreEvent $preEvent, array $mappedQueryParameters)
    {
        $options = $preEvent->getOptions();
        $term = null;
        if ($preEvent->tryPopPendingQueryParameter(CourseProvider::TERM_QUERY_PARAMETER, $term)) {
            $options[CourseProvider::TERM_QUERY_PARAMETER] = $term;
        }
        $organization = null;
        if ($preEvent->tryPopPendingQueryParameter(CourseProvider::ORGANIZATION_QUERY_PARAMETER, $organization)) {
            $options[CourseProvider::ORGANIZATION_QUERY_PARAMETER] = $organization;
        }
        $lecturer = null;
        if ($preEvent->tryPopPendingQueryParameter(CourseProvider::LECTURER_QUERY_PARAMETER, $lecturer)) {
            $options[CourseProvider::LECTURER_QUERY_PARAMETER] = $lecturer;
        }
        $preEvent->setOptions($options);
    }
}
