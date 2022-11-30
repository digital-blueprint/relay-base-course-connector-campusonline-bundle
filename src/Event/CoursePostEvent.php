<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event;

use Dbp\Relay\CoreBundle\LocalData\LocalDataPostEvent;

class CoursePostEvent extends LocalDataPostEvent
{
    public const NAME = 'dbp.relay.relay_base_course_connector_campusonline.course_event.post';
}
