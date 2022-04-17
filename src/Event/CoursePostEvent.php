<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event;

use Dbp\CampusonlineApi\LegacyWebService\Course\CourseData;
use Dbp\Relay\BaseCourseBundle\Entity\Course;
use Dbp\Relay\CoreBundle\Event\LocalDataAwareEvent;

class CoursePostEvent extends LocalDataAwareEvent
{
    public const NAME = 'dbp.relay.relay_base_course_connector_campusonline.course_event.post';

    private $course;
    private $courseData;

    public function __construct(Course $course, CourseData $courseData)
    {
        parent::__construct($course);

        $this->course = $course;
        $this->courseData = $courseData;
    }

    public function getCourseData(): CourseData
    {
        return $this->courseData;
    }

    public function getCourse(): Course
    {
        return $this->course;
    }
}
