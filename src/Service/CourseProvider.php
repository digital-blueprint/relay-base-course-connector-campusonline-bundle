<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service;

use Dbp\CampusonlineApi\Helpers\Filters;
use Dbp\CampusonlineApi\LegacyWebService\ApiException;
use Dbp\CampusonlineApi\LegacyWebService\Course\CourseData;
use Dbp\CampusonlineApi\LegacyWebService\ResourceApi;
use Dbp\Relay\BaseCourseBundle\API\CourseProviderInterface;
use Dbp\Relay\BaseCourseBundle\Entity\Course;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event\CoursePostEvent;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event\CoursePreEvent;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\LocalData\LocalDataEventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;

class CourseProvider implements CourseProviderInterface
{
    public const ORGANIZATION_QUERY_PARAMETER = 'organization';
    public const LECTURER_QUERY_PARAMETER = 'lecturer';
    public const ALL_ITEMS = CourseApi::ALL_ITEMS;

    private CourseApi $courseApi;
    private LocalDataEventDispatcher $eventDispatcher;

    public function __construct(CourseApi $courseApi, EventDispatcherInterface $eventDispatcher)
    {
        $this->courseApi = $courseApi;
        $this->eventDispatcher = new LocalDataEventDispatcher(Course::class, $eventDispatcher);
    }

    /**
     * @param array $options Available options:
     *                       * 'lang' ('de' or 'en')
     *                       * LocalData::INCLUDE_PARAMETER_NAME: Available attributes: 'language', 'typeName', 'code', 'description', 'teachingTerm', 'numberOfCredits', 'levelUrl', 'admissionUrl', 'syllabusUrl', examsUrl', 'datesUrl'
     *
     * @throws ApiError
     */
    public function getCourseById(string $identifier, array $options = []): ?Course
    {
        $this->eventDispatcher->onNewOperation($options);
        $course = null;
        try {
            $courseData = $this->courseApi->getCourseById($identifier, $options);
            $course = $this->createCourseFromCourseData($courseData);
        } catch (ApiException $apiException) {
            self::dispatchException($apiException, $identifier);
        }

        return $course;
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

        $preEvent = new CoursePreEvent($options);
        $this->eventDispatcher->dispatch($preEvent);
        $options = $preEvent->getOptions();

        self::addFilterOptions($options);

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
                    $intersection = array_uintersect($courseDataArray, $coursesByPersonDataArray, function (mixed $a, mixed $b) {
                        return self::compareCourses($a, $b);
                    });
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
        $courses = [];
        try {
            $courses = $this->courseApi->getCourses($currentPageNumber, $maxNumItemsPerPage, $options);
        } catch (ApiException $e) {
            self::dispatchException($e);
        }

        return $courses;
    }

    /**
     * @throws ApiError
     */
    private function getCoursesByOrganization(string $orgUnitId, int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): array
    {
        $courses = [];
        try {
            $courses = $this->courseApi->getCoursesByOrganization($orgUnitId, $currentPageNumber, $maxNumItemsPerPage, $options);
        } catch (ApiException $apiException) {
            self::dispatchException($apiException, $orgUnitId);
        }

        return $courses;
    }

    private function getCoursesByLecturer(string $lecturerId, int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): array
    {
        $courses = [];
        try {
            $courses = $this->courseApi->getCoursesByLecturer($lecturerId, $currentPageNumber, $maxNumItemsPerPage, $options);
        } catch (ApiException $e) {
            self::dispatchException($e, $lecturerId);
        }

        return $courses;
    }

    /**
     * @param array $options Available options:
     *                       * Locale::LANGUAGE_OPTION (language in ISO 639‑1 format)
     *
     * @return string[]
     *
     * @throws ApiError
     */
    public function getAttendeesByCourse(string $courseId, int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): array
    {
        $attendees = [];
        try {
            $attendees = array_map(function ($personData) {
                return $personData->getIdentifier();
            }, $this->courseApi->getStudentsByCourse($courseId, $currentPageNumber, $maxNumItemsPerPage, $options));
        } catch (ApiException $e) {
            self::dispatchException($e, $courseId);
        }

        return $attendees;
    }

    private function createCourseFromCourseData(CourseData $courseData): Course
    {
        $course = new Course();
        $course->setIdentifier($courseData->getIdentifier());
        $course->setName($courseData->getName());

        $postEvent = new CoursePostEvent($course, $courseData->getData(), $this);
        $this->eventDispatcher->dispatch($postEvent);

        return $course;
    }

    /**
     * NOTE: Campusonline returns '401 unauthorized' for some resources that are not found. So we can't
     * safely return '404' in all cases because '401' is also returned by CO if e.g. the token is not valid.
     *
     * @throws ApiError
     * @throws ApiException
     */
    private static function dispatchException(ApiException $apiException, ?string $identifier = null): void
    {
        if ($apiException->isHttpResponseCode()) {
            switch ($apiException->getCode()) {
                case Response::HTTP_NOT_FOUND:
                    if ($identifier !== null) {
                        throw new ApiError(Response::HTTP_NOT_FOUND, sprintf("Id '%s' could not be found!", $identifier));
                    }
                    break;
                case Response::HTTP_UNAUTHORIZED:
                    throw new ApiError(Response::HTTP_UNAUTHORIZED, sprintf("Id '%s' could not be found or access denied!", $identifier));
            }
            if ($apiException->getCode() >= 500) {
                throw new ApiError(Response::HTTP_BAD_GATEWAY, 'failed to get organizations from Campusonline');
            }
        }
        throw $apiException;
    }

    private static function addFilterOptions(array &$options): void
    {
        if (($searchParameter = $options[Course::SEARCH_PARAMETER_NAME] ?? null) && $searchParameter !== '') {
            unset($options[Course::SEARCH_PARAMETER_NAME]);

            ResourceApi::addFilter($options, CourseData::NAME_ATTRIBUTE, Filters::CONTAINS_CI_OPERATOR, $searchParameter, Filters::LOGICAL_OR_OPERATOR);
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
