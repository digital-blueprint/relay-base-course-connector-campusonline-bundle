<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Apis;

use Dbp\Relay\BaseCourseBundle\Entity\Course;

readonly class CourseAndExtraData
{
    public function __construct(
        private Course $course,
        private array $extraData)
    {
    }

    public function getCourse(): Course
    {
        return $this->course;
    }

    public function getExtraData(): array
    {
        return $this->extraData;
    }
}
