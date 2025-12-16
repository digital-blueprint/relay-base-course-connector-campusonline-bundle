<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\EventSubscriber;

use Dbp\CampusonlineApi\LegacyWebService\Course\CourseData;
use Dbp\CampusonlineApi\LegacyWebService\ResourceData;
use Dbp\Relay\BaseCourseBundle\Entity\Course;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event\CoursePostEvent;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event\CoursePreEvent;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service\CourseProvider;
use Dbp\Relay\CoreBundle\LocalData\AbstractLocalDataEventSubscriber;
use Dbp\Relay\CoreBundle\LocalData\LocalDataPostEvent;

class CourseEventSubscriber extends AbstractLocalDataEventSubscriber
{
    public const LECTURERS_LOCAL_DATA_ATTRIBUTE = 'lecturers';
    public const ATTENDEES_LOCAL_DATA_ATTRIBUTE = 'attendees';

    protected static function getSubscribedEventNames(): array
    {
        return [
            CoursePreEvent::class,
            CoursePostEvent::class,
        ];
    }

    public function __construct(private readonly CourseProvider $courseProvider)
    {
    }

    protected function onPostEvent(LocalDataPostEvent $postEvent, array &$localDataAttributes): void
    {
        parent::onPostEvent($postEvent, $localDataAttributes);

        if ($postEvent->isLocalDataAttributeRequested(self::LECTURERS_LOCAL_DATA_ATTRIBUTE)) {
            if ($this->courseProvider->isLegacy()) {
                $lecturerIds = [];
                foreach ($postEvent->getSourceData()[CourseData::CONTACTS_ATTRIBUTE] ?? [] as $contactData) {
                    if ($lecturerId = $contactData[ResourceData::IDENTIFIER_ATTRIBUTE] ?? null) {
                        $lecturerIds[] = $lecturerId;
                    }
                }
            } else {
                $course = $postEvent->getEntity();
                assert($course instanceof Course);
                $lecturerIds = $this->courseProvider->getLecturersByCourse(
                    $course->getIdentifier(), 1, 9999);
            }
            $postEvent->setLocalDataAttribute(self::LECTURERS_LOCAL_DATA_ATTRIBUTE, $lecturerIds);
        }
        if ($postEvent->isLocalDataAttributeRequested(self::ATTENDEES_LOCAL_DATA_ATTRIBUTE)
            && false === $this->courseProvider->isLegacy()) {
            $course = $postEvent->getEntity();
            assert($course instanceof Course);

            $attendeeIds = $this->courseProvider->getAttendeesByCourse(
                $course->getIdentifier(), 1, 9999);

            $postEvent->setLocalDataAttribute(self::ATTENDEES_LOCAL_DATA_ATTRIBUTE, $attendeeIds);
        }
    }
}
