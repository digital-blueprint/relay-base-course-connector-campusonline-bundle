<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event;

use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service\CourseProvider;
use Dbp\Relay\CoreBundle\LocalData\LocalDataAwareInterface;
use Dbp\Relay\CoreBundle\LocalData\LocalDataPostEvent;

class CoursePostEvent extends LocalDataPostEvent
{
    private CourseProvider $courseProvider;

    public function __construct(LocalDataAwareInterface $entity, array $sourceData, CourseProvider $courseApi)
    {
        parent::__construct($entity, $sourceData);

        $this->courseProvider = $courseApi;
    }

    public function getCourseProvider(): CourseProvider
    {
        return $this->courseProvider;
    }
}
