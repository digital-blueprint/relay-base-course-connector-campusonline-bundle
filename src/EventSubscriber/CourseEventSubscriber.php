<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\EventSubscriber;

use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event\CoursePostEvent;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event\CoursePreEvent;
use Dbp\Relay\CoreBundle\LocalData\AbstractLocalDataEventSubscriber;

class CourseEventSubscriber extends AbstractLocalDataEventSubscriber
{
    protected static function getSubscribedEventNames(): array
    {
        return [
            CoursePreEvent::class,
            CoursePostEvent::class,
        ];
    }
}