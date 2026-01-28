<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event;

use Dbp\Relay\BaseCourseBundle\Entity\CourseEvent;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service\CourseProvider;
use Dbp\Relay\CoreBundle\LocalData\LocalDataPostEvent;

class CourseEventPostEvent extends LocalDataPostEvent
{
    private CourseProvider $courseProvider;

    public function __construct(CourseEvent $entity, array $sourceData, CourseProvider $courseApi, array $options)
    {
        parent::__construct($entity, $sourceData, $options);

        $this->courseProvider = $courseApi;
    }

    public function getCourseProvider(): CourseProvider
    {
        return $this->courseProvider;
    }
}
