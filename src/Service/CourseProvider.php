<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service;

use Dbp\CampusonlineApi\Helpers\FullPaginator as CoFullPaginator;
use Dbp\CampusonlineApi\LegacyWebService\ApiException;
use Dbp\CampusonlineApi\LegacyWebService\Course\CourseData;
use Dbp\CampusonlineApi\LegacyWebService\Person\PersonData;
use Dbp\CampusonlineApi\LegacyWebService\ResourceData;
use Dbp\Relay\BaseCourseBundle\API\CourseProviderInterface;
use Dbp\Relay\BaseCourseBundle\Entity\Course;
use Dbp\Relay\BaseCourseBundle\Entity\CourseAttendee;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event\CoursePostEvent;
use Dbp\Relay\BasePersonBundle\API\PersonProviderInterface;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\LocalData\LocalData;
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

    /*
     * @throws ApiError
     */
    public function getCourseById(string $identifier, array $options = []): Course
    {
        $this->eventDispatcher->initRequestedLocalDataAttributes(LocalData::getIncludeParameter($options));

        $courseData = null;
        try {
            $courseData = $this->courseApi->getCourseById($identifier, $options);
        } catch (\Exception $e) {
            self::dispatchCampusonlineException($e, $identifier);
        }

        return $this->createCourseFromCourseData($courseData);
    }

    /*
     * @throws ApiError
     */
    public function getCourses(array $options = []): Paginator
    {
        $this->eventDispatcher->initRequestedLocalDataAttributes(LocalData::getIncludeParameter($options));
        $this->addFilterOptions($options);

        $courses = [];

        try {
            $paginator = $this->courseApi->getCourses($options);
            foreach ($paginator->getItems() as $courseData) {
                $courses[] = $this->createCourseFromCourseData($courseData);
            }
        } catch (ApiException $e) {
            throw new ApiError(Response::HTTP_INTERNAL_SERVER_ERROR, $e->getMessage());
        }

        if (Pagination::isPartialPagination($options)) {
            return new PartialPaginator($courses, $paginator->getCurrentPageNumber(), $paginator->getMaxNumItemsPerPage());
        } else {
            if ($paginator instanceof CoFullPaginator) {
                return new FullPaginator($courses, $paginator->getCurrentPageNumber(), $paginator->getMaxNumItemsPerPage(), $paginator->getTotalNumItems());
            } else {
                throw new ApiError(500, 'camppusonline api returned invalid paginator');
            }
        }
    }

    /*
     * @throws ApiError
    */
    public function getCoursesByOrganization(string $orgUnitId, array $options = []): Paginator
    {
        $this->eventDispatcher->initRequestedLocalDataAttributes(LocalData::getIncludeParameter($options));
        $this->addFilterOptions($options);

        $paginator = null;
        $courses = [];

        try {
            $paginator = $this->courseApi->getCoursesByOrganization($orgUnitId, $options);
            foreach ($paginator->getItems() as $courseData) {
                $courses[] = $this->createCourseFromCourseData($courseData);
            }
        } catch (ApiException $e) {
            self::dispatchCampusonlineException($e, $orgUnitId);
        }

        if (Pagination::isPartialPagination($options)) {
            return new PartialPaginator($courses, $paginator->getCurrentPageNumber(), $paginator->getMaxNumItemsPerPage());
        } else {
            if ($paginator instanceof CoFullPaginator) {
                return new FullPaginator($courses, $paginator->getCurrentPageNumber(), $paginator->getMaxNumItemsPerPage(), $paginator->getTotalNumItems());
            } else {
                throw new ApiError(500, 'camppusonline api returned invalid paginator');
            }
        }
    }

    public function getCoursesByLecturer(string $lecturerId, array $options = []): Paginator
    {
        $this->eventDispatcher->initRequestedLocalDataAttributes(LocalData::getIncludeParameter($options));
        $this->addFilterOptions($options);

        $lecturer = $this->personProvider->getPerson($lecturerId);
        $coEmployeeId = $lecturer->getExtraData('coEmployeeId');
        if (empty($coEmployeeId)) {
            throw new NotFoundHttpException(sprintf("lecturer with id '%s' not found", $lecturerId));
        }

        $paginator = null;
        $courses = [];

        try {
            $paginator = $this->courseApi->getCoursesByLecturer($coEmployeeId, $options);
            foreach ($paginator->getItems() as $courseData) {
                $courses[] = $this->createCourseFromCourseData($courseData);
            }
        } catch (ApiException $e) {
            self::dispatchCampusonlineException($e, $lecturerId);
        }

        if (Pagination::isPartialPagination($options)) {
            return new PartialPaginator($courses, $paginator->getCurrentPageNumber(), $paginator->getMaxNumItemsPerPage());
        } else {
            if ($paginator instanceof CoFullPaginator) {
                return new FullPaginator($courses, $paginator->getCurrentPageNumber(), $paginator->getMaxNumItemsPerPage(), $paginator->getTotalNumItems());
            } else {
                throw new ApiError(500, 'camppusonline api returned invalid paginator');
            }
        }
    }

    public function getAttendeesByCourse(string $courseId, array $options = []): Paginator
    {
        $paginator = null;
        $attendees = [];

        try {
            $paginator = $this->courseApi->getStudentsByCourse($courseId, $options);
            foreach ($paginator->getItems() as $personData) {
                $attendees[] = self::createCourseAttendeeFromPersonData($personData);
            }
        } catch (ApiException $e) {
            self::dispatchCampusonlineException($e, $courseId);
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

    private function addFilterOptions(array &$options)
    {
        if (($searchParameter = $options[Course::SEARCH_PARAMETER_NAME] ?? null) && $searchParameter !== '') {
            $options[ResourceData::NAME_SEARCH_FILTER_NAME] = $searchParameter;
        }
    }
}
