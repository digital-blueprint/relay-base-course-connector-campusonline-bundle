<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event;

use Dbp\Relay\CoreBundle\LocalData\LocalDataAwarePreEvent;

class CoursePreEvent extends LocalDataAwarePreEvent
{
    public const NAME = 'dbp.relay.relay_base_course_connector_campusonline.course_event.pre';
}
