<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service;

use Dbp\Relay\BaseCourseBundle\Entity\CourseEvent;

readonly class CourseEventAndExtraData
{
    public function __construct(
        private CourseEvent $courseEvent,
        private array $extraData)
    {
    }

    public function getCourseEvent(): CourseEvent
    {
        return $this->courseEvent;
    }

    public function getExtraData(): array
    {
        return $this->extraData;
    }
}
