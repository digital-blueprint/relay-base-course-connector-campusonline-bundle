<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\EventSubscriber;

use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event\CoursePostEvent;
use Dbp\Relay\CoreBundle\LocalData\AbstractLocalDataPostEventSubscriber;

class CoursePostEventSubscriber extends AbstractLocalDataPostEventSubscriber
{
    public static function getSubscribedEventName(): string
    {
        return CoursePostEvent::class;
    }
}
