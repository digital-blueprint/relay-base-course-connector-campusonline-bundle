<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service;

use Dbp\CampusonlineApi\Helpers\FullPaginator as CoFullPaginator;
use Dbp\CampusonlineApi\Helpers\Pagination as CoPagination;
use Dbp\CampusonlineApi\Helpers\Paginator as CoPaginator;
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
use Dbp\Relay\CoreBundle\LocalData\LocalDataAwareEventDispatcher;
use Dbp\Relay\CoreBundle\Pagination\FullPaginator;
use Dbp\Relay\CoreBundle\Pagination\Pagination;
use Dbp\Relay\CoreBundle\Pagination\Paginator;
use Dbp\Relay\CoreBundle\Pagination\PartialPaginator;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CourseProvider implements CourseProviderInterface
{
    private const ORGANIZATION_QUERY_PARAMETER = 'organization';
    private const LECTURER_QUERY_PARAMETER = 'lecturer';

    /** @var CourseApi */
    private $courseApi;

    /** @var LocalDataAwareEventDispatcher */
    private $eventDispatcher;

    /** @var PersonProviderInterface */
    private $personProvider;

    public function __construct(CourseApi $courseApi, EventDispatcherInterface $eventDispatcher, PersonProviderInterface $personProvider)
    {
        $this->courseApi = $courseApi;
        $this->eventDispatcher = new LocalDataAwareEventDispatcher(Course::class, $eventDispatcher);
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
     *                       * 'lang' ('de' or 'en')
     *                       * Course::SEARCH_PARAMETER_NAME (partial, case-insensitive text search on 'name' attribute)
     *                       * LocalData::INCLUDE_PARAMETER_NAME: Available attributes: 'language', 'typeName', 'code', 'description', 'teachingTerm', 'numberOfCredits', 'levelUrl', 'admissionUrl', 'syllabusUrl', examsUrl', 'datesUrl'
     *                       * LocalData::QUERY_PARAMETER_NAME
     *
     * @throws ApiError
     */
    public function getCourses(array $options = []): Paginator
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

        $coPaginator = null;

        if ($filterByOrganizationId || $filterByLecturerId) {
            $tmpPaginationOptions = [];
            if ($filterByOrganizationId && $filterByLecturerId) {
                // request paginators holding the whole set of results since we need to intersect them ->
                // temporarily remove pagination options
                Pagination::addOptions($tmpPaginationOptions, $options);
                Pagination::removeOptions($options);
            }

            if ($filterByOrganizationId) {
                $coPaginator = $this->getCoursesByOrganization($organizationId, $options);
            }
            if ($filterByLecturerId) {
                $coCursesByPersonPaginator = $this->getCoursesByLecturer($lecturerId, $options);

                if (!$filterByOrganizationId) {
                    $coPaginator = $coCursesByPersonPaginator;
                } else {
                    $intersection = array_uintersect($coPaginator->getItems(), $coCursesByPersonPaginator->getItems(),
                        'Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service\CourseProvider::compareCourses');
                    $courses = array_values($intersection);

                    // re-add pagination options
                    Pagination::addOptions($options, $tmpPaginationOptions);
                    $coPaginator = CoPagination::createPaginatorFromWholeResult($courses, $options);
                }
            }
        } else {
            $coPaginator = $this->getCoursesInternal($options);
        }

        $courses = [];
        foreach ($coPaginator->getItems() as $courseData) {
            $courses[] = $this->createCourseFromCourseData($courseData);
        }

        if (Pagination::isPartialPagination($options)) {
            return new PartialPaginator($courses, $coPaginator->getCurrentPageNumber(), $coPaginator->getMaxNumItemsPerPage());
        } else {
            if ($coPaginator instanceof CoFullPaginator) {
                return new FullPaginator($courses, $coPaginator->getCurrentPageNumber(), $coPaginator->getMaxNumItemsPerPage(), $coPaginator->getTotalNumItems());
            } else {
                throw ApiError::withDetails(Response::HTTP_INTERNAL_SERVER_ERROR, 'camppusonline api returned invalid paginator');
            }
        }
    }

    /*
 * @throws ApiError
*/
    private function getCoursesInternal(array $options = []): CoPaginator
    {
        try {
            return $this->courseApi->getCourses($options);
        } catch (ApiException $e) {
            throw self::toApiError($e, '');
        }
    }

    /*
     * @throws ApiError
    */
    private function getCoursesByOrganization(string $orgUnitId, array $options = []): CoPaginator
    {
        try {
            return $this->courseApi->getCoursesByOrganization($orgUnitId, $options);
        } catch (ApiException $e) {
            throw self::toApiError($e, $orgUnitId);
        }
    }

    private function getCoursesByLecturer(string $lecturerId, array $options = []): CoPaginator
    {
        //$lecturer = $this->personProvider->getPerson($lecturerId);
        $coEmployeeId = 'E6E1D21423F528EC'; //$lecturer->getExtraData('coEmployeeId');
        if (Tools::isNullOrEmpty($coEmployeeId)) {
            throw new NotFoundHttpException(sprintf("Employee with id '%s' not found", $lecturerId));
        }

        try {
            return $this->courseApi->getCoursesByLecturer($coEmployeeId, $options);
        } catch (ApiException $e) {
            throw self::toApiError($e, $lecturerId);
        }
    }

    public function getAttendeesByCourse(string $courseId, array $options = []): Paginator
    {
        $attendees = [];
        try {
            $paginator = $this->courseApi->getStudentsByCourse($courseId, $options);
            foreach ($paginator->getItems() as $personData) {
                $attendees[] = self::createCourseAttendeeFromPersonData($personData);
            }
        } catch (ApiException $e) {
            throw self::toApiError($e, $courseId);
        }

        if (Pagination::isPartialPagination($options)) {
            return new PartialPaginator($attendees, $paginator->getCurrentPageNumber(), $paginator->getMaxNumItemsPerPage());
        } else {
            if ($paginator instanceof CoFullPaginator) {
                return new FullPaginator($attendees, $paginator->getCurrentPageNumber(), $paginator->getMaxNumItemsPerPage(), $paginator->getTotalNumItems());
            } else {
                throw new ApiError(500, 'camppusonline api returned invalid paginator');
            }
        }
    }

    private function createCourseFromCourseData(CourseData $courseData): Course
    {
        $course = new Course();
        $course->setIdentifier($courseData->getIdentifier());
        $course->setName($courseData->getName());
        $course->setType($courseData->getType());

        $postEvent = new CoursePostEvent($course, $courseData);
        $this->eventDispatcher->dispatch($postEvent, CoursePostEvent::NAME);

        return $postEvent->getEntity();
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
