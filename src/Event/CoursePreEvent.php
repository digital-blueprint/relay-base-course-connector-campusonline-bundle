<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event;

use Dbp\Relay\CoreBundle\LocalData\LocalDataPreEvent;

class CoursePreEvent extends LocalDataPreEvent
{
    public const NAME = 'dbp.relay.relay_base_course_connector_campusonline.course_event.pre';
}
