<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\EventSubscriber;

use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event\CourseEventPostEvent;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service\CourseProvider;
use Dbp\Relay\CoreBundle\LocalData\AbstractLocalDataEventSubscriber;

class CourseEventEventSubscriber extends AbstractLocalDataEventSubscriber
{
    public const COURSE_GROUP_UID_SOURCE_DATA_ATTRIBUTE = 'courseGroupUid';
    public const ROOM_UID_SOURCE_DATA_ATTRIBUTE = 'roomUid';
    public const STATUS_TYPE_KEY_SOURCE_DATA_ATTRIBUTE = 'statusTypeKey';
    public const EVENT_TYPE_KEY_SOURCE_DATA_ATTRIBUTE = 'eventTypeKey';
    public const COMMENT_SOURCE_DATA_ATTRIBUTE = 'comment';
    public const APPLICATION_TYPE_KEY_SOURCE_DATA_ATTRIBUTE = 'applicationTypeKey';
    public const RESOURCE_URL_SOURCE_DATA_ATTRIBUTE = 'resourceUrl';
    public const EXTERNAL_OBJECT_UID_SOURCE_DATA_ATTRIBUTE = 'externalObjectUid';
    public const RESOURCE_UID_SOURCE_DATA_ATTRIBUTE = 'resourceUId';

    protected static function getSubscribedEventNames(): array
    {
        return [
            CourseEventPostEvent::class,
        ];
    }

    public function __construct(private readonly CourseProvider $courseProvider)
    {
        parent::__construct('BaseCourseEvent');
    }
}
