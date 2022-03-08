<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event;

use Dbp\CampusonlineApi\LegacyWebService\Course\CourseData;
use Dbp\Relay\BaseCourseBundle\Entity\Course;
use Symfony\Contracts\EventDispatcher\Event;

class CourseProviderPostEvent extends Event
{
    public const NAME = 'dbp.relay.relay_base_course_connector_campusonline.course_provider.post';

    private $baseCourse;
    private $courseData;

    public function __construct(Course $baseCourse, CourseData $courseData)
    {
        $this->baseCourse = $baseCourse;
        $this->courseData = $courseData;
    }

    public function getCourseData(): CourseData
    {
        return $this->courseData;
    }

    public function getCourse(): Course
    {
        return $this->baseCourse;
    }
}
