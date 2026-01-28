<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\EventSubscriber;

use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event\CourseEventPostEvent;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service\CourseProvider;
use Dbp\Relay\CoreBundle\LocalData\AbstractLocalDataEventSubscriber;

class CourseEventEventSubscriber extends AbstractLocalDataEventSubscriber
{
    protected static function getSubscribedEventNames(): array
    {
        return [
            CourseEventPostEvent::class,
        ];
    }

    public function __construct(private readonly CourseProvider $courseProvider)
    {
        parent::__construct('BaseCourseEvent');
    }
}
