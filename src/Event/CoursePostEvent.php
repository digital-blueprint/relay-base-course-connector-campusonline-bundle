<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event;

use Dbp\CampusonlineApi\LegacyWebService\Course\CourseData;
use Dbp\Relay\BaseCourseBundle\Entity\Course;
use Dbp\Relay\CoreBundle\LocalData\LocalDataAwareEvent;

class CoursePostEvent extends LocalDataAwareEvent
{
    public const NAME = 'dbp.relay.relay_base_course_connector_campusonline.course_event.post';

    /** @var CourseData */
    private $courseData;

    /** @var Course */
    private $course;

    public function __construct(Course $course, CourseData $courseData)
    {
        parent::__construct($course);

        $this->course = $course;
        $this->courseData = $courseData;
    }

    public function getSourceData(): CourseData
    {
        return $this->courseData;
    }

    public function getEntity(): Course
    {
        return $this->course;
    }
}
