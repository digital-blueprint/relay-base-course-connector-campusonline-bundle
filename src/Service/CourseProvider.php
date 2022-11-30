<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service;

use Dbp\CampusonlineApi\LegacyWebService\ApiException;
use Dbp\CampusonlineApi\LegacyWebService\Course\CourseData;
use Dbp\CampusonlineApi\LegacyWebService\Person\PersonData;
use Dbp\CampusonlineApi\LegacyWebService\ResourceData;
use Dbp\Relay\BaseCourseBundle\API\CourseProviderInterface;
use Dbp\Relay\BaseCourseBundle\Entity\Course;
use Dbp\Relay\BaseCourseBundle\Entity\CourseAttendee;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event\CoursePostEvent;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event\CoursePreEvent;
use Dbp\Relay\BasePersonBundle\API\PersonProviderInterface;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Helpers\Tools;
use Dbp\Relay\CoreBundle\LocalData\LocalDataEventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CourseProvider implements CourseProviderInterface
{
    private const ORGANIZATION_QUERY_PARAMETER = 'organization';
    private const LECTURER_QUERY_PARAMETER = 'lecturer';

    /** @var CourseApi */
    private $courseApi;

    /** @var LocalDataEventDispatcher */
    private $eventDispatcher;

    /** @var PersonProviderInterface */
    private $personProvider;

    public function __construct(CourseApi $courseApi, EventDispatcherInterface $eventDispatcher, PersonProviderInterface $personProvider)
    {
        $this->courseApi = $courseApi;
        $this->eventDispatcher = new LocalDataEventDispatcher(Course::class, $eventDispatcher);
        $this->personProvider = $personProvider;
    }

    /**
     * @param array $options Available options:
     *                       * 'lang' ('de' or 'en')
     *                       * LocalData::INCLUDE_PARAMETER_NAME: Available attributes: 'language', 'typeName', 'code', 'description', 'teachingTerm', 'numberOfCredits', 'levelUrl', 'admissionUrl', 'syllabusUrl', examsUrl', 'datesUrl'
     *
     * @throws ApiError
     */
    public function getCourseById(string $identifier, array $options = []): Course
    {
        $this->eventDispatcher->onNewOperation($options);

        $courseData = null;
        try {
            $courseData = $this->courseApi->getCourseById($identifier, $options);
        } catch (\Exception $e) {
            throw self::toApiError($e, $identifier);
        }

        return $this->createCourseFromCourseData($courseData);
    }

    /**
     * @param array $options Available options:
     *                       * Locale::LANGUAGE_OPTION (language in ISO 639‑1 format)
     *                       * Course::SEARCH_PARAMETER_NAME (partial, case-insensitive text search on 'name' attribute)
     *                       * LocalData::INCLUDE_PARAMETER_NAME
     *                       * LocalData::QUERY_PARAMETER_NAME
     *
     * @return Course[]
     *
     * @throws ApiError
     */
    public function getCourses(int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): array
    {
        $this->eventDispatcher->onNewOperation($options);

        $preEvent = new CoursePreEvent();
        $this->eventDispatcher->dispatch($preEvent, CoursePreEvent::NAME);
        $options = array_merge($options, $preEvent->getQueryParameters());

        $this->addFilterOptions($options);

        $organizationId = $options[self::ORGANIZATION_QUERY_PARAMETER] ?? '';
        $lecturerId = $options[self::LECTURER_QUERY_PARAMETER] ?? '';

        $filterByOrganizationId = $organizationId !== '';
        $filterByLecturerId = $lecturerId !== '';

        $courseDataArray = null;

        if ($filterByOrganizationId || $filterByLecturerId) {
            if ($filterByOrganizationId && $filterByLecturerId) {
                // request the whole set of results since we need to intersect them ->
                $currentPageNumber = 1;
                $maxNumItemsPerPage = CourseApi::ALL_ITEMS;
            }

            if ($filterByOrganizationId) {
                $courseDataArray = $this->getCoursesByOrganization($organizationId, $currentPageNumber, $maxNumItemsPerPage, $options);
            }
            if ($filterByLecturerId) {
                $coursesByPersonDataArray = $this->getCoursesByLecturer($lecturerId, $currentPageNumber, $maxNumItemsPerPage, $options);

                if (!$filterByOrganizationId) {
                    $courseDataArray = $coursesByPersonDataArray;
                } else {
                    $intersection = array_uintersect($courseDataArray, $coursesByPersonDataArray,
                        'Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service\CourseProvider::compareCourses');
                    $courseDataArray = array_values($intersection);
                }
            }
        } else {
            $courseDataArray = $this->getCoursesInternal($currentPageNumber, $maxNumItemsPerPage, $options);
        }

        $courses = [];
        foreach ($courseDataArray as $courseData) {
            $courses[] = $this->createCourseFromCourseData($courseData);
        }

        return $courses;
    }

    /**
     * @throws ApiError
     */
    private function getCoursesInternal(int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): array
    {
        try {
            return $this->courseApi->getCourses($currentPageNumber, $maxNumItemsPerPage, $options);
        } catch (ApiException $e) {
            throw self::toApiError($e, '');
        }
    }

    /**
     * @throws ApiError
     */
    private function getCoursesByOrganization(string $orgUnitId, int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): array
    {
        try {
            return $this->courseApi->getCoursesByOrganization($orgUnitId, $currentPageNumber, $maxNumItemsPerPage, $options);
        } catch (ApiException $e) {
            throw self::toApiError($e, $orgUnitId);
        }
    }

    private function getCoursesByLecturer(string $lecturerId, int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): array
    {
        $lecturer = $this->personProvider->getPerson($lecturerId);
        $coEmployeeId = $lecturer->getExtraData('coEmployeeId');
        if (Tools::isNullOrEmpty($coEmployeeId)) {
            throw new NotFoundHttpException(sprintf("Employee with id '%s' not found", $lecturerId));
        }

        try {
            return $this->courseApi->getCoursesByLecturer($coEmployeeId, $currentPageNumber, $maxNumItemsPerPage, $options);
        } catch (ApiException $e) {
            throw self::toApiError($e, $lecturerId);
        }
    }

    /**
     * @param array $options Available options:
     *                       * Locale::LANGUAGE_OPTION (language in ISO 639‑1 format)
     *
     * @return CourseAttendee[]
     *
     * @throws ApiError
     */
    public function getAttendeesByCourse(string $courseId, int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): array
    {
        $attendees = [];
        try {
            foreach ($this->courseApi->getStudentsByCourse($courseId, $currentPageNumber, $maxNumItemsPerPage, $options) as $personData) {
                $attendees[] = self::createCourseAttendeeFromPersonData($personData);
            }
        } catch (ApiException $e) {
            throw self::toApiError($e, $courseId);
        }

        return $attendees;
    }

    private function createCourseFromCourseData(CourseData $courseData): Course
    {
        $course = new Course();
        $course->setIdentifier($courseData->getIdentifier());
        $course->setName($courseData->getName());
        $course->setType($courseData->getType());

        $postEvent = new CoursePostEvent($course, $courseData->getData());
        $this->eventDispatcher->dispatch($postEvent, CoursePostEvent::NAME);

        return $course;
    }

    private static function createCourseAttendeeFromPersonData(PersonData $personData): CourseAttendee
    {
        $attendee = new CourseAttendee();
        // note: CO person ID is not the same as LDAP person ID,
        // which is currently used as BasePerson entity ID
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
    private static function toApiError(ApiException $e, string $identifier): ApiError
    {
        if ($e->isHttpResponseCode()) {
            switch ($e->getCode()) {
                case Response::HTTP_NOT_FOUND:
                    return ApiError::withDetails(Response::HTTP_NOT_FOUND, sprintf("Id '%s' could not be found!", $identifier));
                case Response::HTTP_UNAUTHORIZED:
                    return ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, sprintf("Id '%s' could not be found or access denied!", $identifier));
                default:
                    break;
            }
        }

        return ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, $e->getMessage());
    }

    private function addFilterOptions(array &$options)
    {
        if (($searchParameter = $options[Course::SEARCH_PARAMETER_NAME] ?? null) && $searchParameter !== '') {
            $options[ResourceData::NAME_SEARCH_FILTER_NAME] = $searchParameter;
        }
    }

    public static function compareCourses(CourseData $a, CourseData $b): int
    {
        if ($a->getIdentifier() > $b->getIdentifier()) {
            return 1;
        } elseif ($a->getIdentifier() === $b->getIdentifier()) {
            return 0;
        } else {
            return -1;
        }
    }
}
