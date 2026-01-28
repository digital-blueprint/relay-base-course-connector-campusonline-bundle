<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\EventSubscriber;

use Dbp\Relay\BaseCourseBundle\Entity\Course;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event\CoursePostEvent;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event\CoursePreEvent;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service\CourseProvider;
use Dbp\Relay\CoreBundle\LocalData\AbstractLocalDataEventSubscriber;
use Dbp\Relay\CoreBundle\LocalData\LocalDataPostEvent;

class CourseEventSubscriber extends AbstractLocalDataEventSubscriber
{
    public const LECTURERS_SOURCE_DATA_ATTRIBUTE = 'lecturers';
    public const ATTENDEES_SOURCE_DATA_ATTRIBUTE = 'attendees';
    public const COURSE_GROUPS_SOURCE_DATA_ATTRIBUTE = 'courseGroups';
    public const DESCRIPTION_SOURCE_DATA_ATTRIBUTE = 'description';
    public const STATUS_WITHIN_CURRICULUM_URL_SOURCE_DATA_ATTRIBUTE = 'statusWithinCurriculumUrl';
    public const OBJECTIVE_SOURCE_DATA_ATTRIBUTE = 'objective';
    public const ATTENDEE_GROUP_LIST_URL_SOURCE_DATA_ATTRIBUTE = 'attendeeGroupListUrl';
    public const TYPE_NAME_SOURCE_DATA_ATTRIBUTE = 'typeName';
    public const COURSE_TYPE_KEY_SOURCE_ATTRIBUTE = 'courseTypeKey';

    protected static function getSubscribedEventNames(): array
    {
        return [
            CoursePreEvent::class,
            CoursePostEvent::class,
        ];
    }

    public function __construct(private readonly CourseProvider $courseProvider)
    {
        parent::__construct('BaseCourse');
    }

    protected function getAttributeValue(LocalDataPostEvent $postEvent, array $attributeMapEntry): mixed
    {
        $course = $postEvent->getEntity();
        assert($course instanceof Course);

        switch ($attributeMapEntry[self::SOURCE_ATTRIBUTE_KEY]) {
            case self::LECTURERS_SOURCE_DATA_ATTRIBUTE:
                return $this->courseProvider->getLecturersByCourse(
                    $course->getIdentifier());

            case self::ATTENDEES_SOURCE_DATA_ATTRIBUTE:
                return $this->courseProvider->getAttendeesByCourse(
                    $course->getIdentifier());

            case self::COURSE_GROUPS_SOURCE_DATA_ATTRIBUTE:
                return $this->courseProvider->getGroupsByCourse(
                    $course->getIdentifier(), $postEvent->getOptions());

            case self::DESCRIPTION_SOURCE_DATA_ATTRIBUTE:
                return $this->courseProvider->getDescriptionByCourse(
                    $course->getIdentifier(), $postEvent->getOptions());

            case self::STATUS_WITHIN_CURRICULUM_URL_SOURCE_DATA_ATTRIBUTE:
                return $this->courseProvider->getCampusOnlineWebBaseUrl().
                    'ee/rest/pages/slc.tm.cp/course-position-in-curriculum/'.$course->getIdentifier();

            case self::OBJECTIVE_SOURCE_DATA_ATTRIBUTE:
                return $this->courseProvider->getObjectiveByCourse(
                    $course->getIdentifier(), $postEvent->getOptions());

            case self::ATTENDEE_GROUP_LIST_URL_SOURCE_DATA_ATTRIBUTE:
                return $this->courseProvider->getCampusOnlineWebBaseUrl().
                    'ee/rest/pages/slc.tm.cp/course-registration/'.$course->getIdentifier();

            case self::TYPE_NAME_SOURCE_DATA_ATTRIBUTE:
                return ($courseTypeKey = $postEvent->getSourceData()[self::COURSE_TYPE_KEY_SOURCE_ATTRIBUTE] ?? null) !== null ?
                    $this->courseProvider->getLocalizedTypeNameForTypeKey($courseTypeKey, $postEvent->getOptions()) : null;
        }

        return parent::getAttributeValue($postEvent, $attributeMapEntry);
    }
}
