<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service;

use Dbp\CampusonlineApi\LegacyWebService\ApiException;
use Dbp\CampusonlineApi\LegacyWebService\Course\CourseData;
use Dbp\CampusonlineApi\LegacyWebService\Person\PersonData;
use Dbp\Relay\BaseCourseBundle\API\CourseProviderInterface;
use Dbp\Relay\BaseCourseBundle\Entity\Course;
use Dbp\Relay\BaseCourseBundle\Entity\CourseAttendee;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event\CoursePostEvent;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\LocalData\LocalDataAwareEventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;

class CourseProvider implements CourseProviderInterface
{
    /** @var CourseApi */
    private $courseApi;

    /** @var LocalDataAwareEventDispatcher */
    private $eventDispatcher;

    public function __construct(CourseApi $courseApi, EventDispatcherInterface $eventDispatcher)
    {
        $this->courseApi = $courseApi;
        $this->eventDispatcher = new LocalDataAwareEventDispatcher(Course::class, $eventDispatcher);
    }

    /*
     * @throws ApiError
     */
    public function getCourseById(string $identifier, array $options = []): ?Course
    {
        $this->eventDispatcher->initRequestedLocalDataAttributes($options['include'] ?? '');

        $courseData = null;
        try {
            $courseData = $this->courseApi->getCourseById($identifier, $options);
        } catch (\Exception $e) {
            self::dispatchCampusonlineException($e, $identifier);
        }

        return $courseData ? $this->createCourseFromCourseData($courseData) : null;
    }

    /*
     * @return Course[]
     *
     * @throws ApiError
     */
    public function getCourses(array $options = []): array
    {
        $this->eventDispatcher->initRequestedLocalDataAttributes($options['include'] ?? '');

        $courses = [];
        try {
            foreach ($this->courseApi->getCourses($options) as $courseData) {
                $courses[] = $this->createCourseFromCourseData($courseData);
            }
        } catch (ApiException $e) {
            throw new ApiError(Response::HTTP_INTERNAL_SERVER_ERROR, $e->getMessage());
        }

        return $courses;
    }

    /*
    * @return Course[]
     *
     * @throws ApiError
    */
    public function getCoursesByOrganization(string $orgUnitId, array $options = []): array
    {
        $this->eventDispatcher->initRequestedLocalDataAttributes($options['include'] ?? '');

        $courses = [];
        try {
            foreach ($this->courseApi->getCoursesByOrganization($orgUnitId, $options) as $courseData) {
                $courses[] = $this->createCourseFromCourseData($courseData);
            }
        } catch (ApiException $e) {
            self::dispatchCampusonlineException($e, $orgUnitId);
        }

        return $courses;
    }

    /**
     * @return Course[]
     */
    public function getCoursesByPerson(string $personId, array $options = []): array
    {
        $this->eventDispatcher->initRequestedLocalDataAttributes($options['include'] ?? '');

        $courses = [];
        try {
            foreach ($this->courseApi->getCoursesByPerson($personId, $options) as $courseData) {
                $courses[] = $this->createCourseFromCourseData($courseData);
            }
        } catch (ApiException $e) {
            self::dispatchCampusonlineException($e, $personId);
        }

        return $courses;
    }

    /**
     * @return CourseAttendee[]
     */
    public function getAttendeesByCourse(string $courseId, array $options = []): array
    {
        $attendees = [];

        try {
            foreach ($this->courseApi->getAttendeesByCourse($courseId, $options) as $attendeeData) {
                $attendees[] = self::createCourseAttendeeFromPersonData($attendeeData);
            }
        } catch (ApiException $e) {
            self::dispatchCampusonlineException($e, $courseId);
        }

        return $attendees;
    }

    private function createCourseFromCourseData(CourseData $courseData): Course
    {
        $course = new Course();
        $course->setIdentifier($courseData->getIdentifier());
        $course->setName($courseData->getName());
        $course->setType($courseData->getType());

        $postEvent = new CoursePostEvent($course, $courseData);
        $this->eventDispatcher->dispatch($postEvent, CoursePostEvent::NAME);

        return $postEvent->getCourse();
    }

    private static function createCourseAttendeeFromPersonData(PersonData $personData): CourseAttendee
    {
        $attendee = new CourseAttendee();
        // note: CO person ID is not the same as LDAP person ID,
        // which is normally used as identifier in base-Person entity
        $attendee->setIdentifier($personData->getIdentifier());
        $attendee->setGivenName($personData->getGivenName());
        $attendee->setFamilyName($personData->getFamilyName());
        $attendee->setEmail($personData->getEmail());

        return $attendee;
    }

    /**
     * NOTE: Campusonline returns '401 unauthorized' for some resources that are not found. So we can't
     * safely return '404' in all cases.
     */
    private static function dispatchCampusonlineException(ApiException $e, string $identifier)
    {
        if ($e->isHttpResponseCode()) {
            switch ($e->getCode()) {
                case Response::HTTP_NOT_FOUND:
                    throw new ApiError(Response::HTTP_NOT_FOUND, sprintf("Id '%s' could not be found!", $identifier));
                    break;
                case Response::HTTP_UNAUTHORIZED:
                    throw new ApiError(Response::HTTP_INTERNAL_SERVER_ERROR, sprintf("Id '%s' could not be found or access denied!", $identifier));
                    break;
                default:
                    break;
            }
        }
        throw new ApiError(Response::HTTP_INTERNAL_SERVER_ERROR, $e->getMessage());
    }
}
