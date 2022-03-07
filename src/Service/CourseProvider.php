<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service;

use Dbp\CampusonlineApi\LegacyWebService\ApiException;
use Dbp\Relay\BaseCourseBundle\API\CourseProviderInterface;
use Dbp\Relay\BaseCourseBundle\Entity\Course;
use Dbp\Relay\BaseCourseBundle\Entity\CourseAttendee;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Symfony\Component\HttpFoundation\Response;

class CourseProvider implements CourseProviderInterface
{
    /*
     * @var CourseApi
     */
    private $courseApi;

    public function __construct(CourseApi $courseApi)
    {
        $this->courseApi = $courseApi;
    }

    /*
     * @throws ApiError
     */
    public function getCourseById(string $identifier, array $options = []): ?Course
    {
        $course = null;
        try {
            $course = $this->courseApi->getCourseById($identifier, $options);
        } catch (\Exception $e) {
            self::dispatchException($e, $identifier);
        }

        return $course;
    }

    /*
     * @return Course[]
     *
     * @throws ApiError
     */
    public function getCourses(array $options = []): array
    {
        try {
            return $this->courseApi->getCourses($options);
        } catch (ApiException $e) {
            throw new ApiError(Response::HTTP_INTERNAL_SERVER_ERROR, $e->getMessage());
        }
    }

    /*
    * @return Course[]
     *
     * @throws ApiError
    */
    public function getCoursesByOrganization(string $orgUnitId, array $options = []): array
    {
        $courses = [];

        try {
            $courses = $this->courseApi->getCoursesByOrganization($orgUnitId, $options);
        } catch (ApiException $e) {
            self::dispatchException($e, $orgUnitId);
        }

        return $courses;
    }

    /**
     * @return Course[]
     */
    public function getCoursesByPerson(string $personId, array $options = []): array
    {
        $courses = [];

        try {
            $courses = $this->courseApi->getCoursesByPerson($personId, $options);
        } catch (ApiException $e) {
            self::dispatchException($e, $personId);
        }

        return $courses;
    }

    /**
     * @return CourseAttendee[]
     */
    public function getAttendeesByCourse(string $courseId, array $options = []): array
    {
        $courses = [];

        try {
            $courses = $this->courseApi->getAttendeesByCourse($courseId, $options);
        } catch (ApiException $e) {
            self::dispatchException($e, $courseId);
        }

        return $courses;
    }

    /**
     * NOTE: Comfortonline returns '401 unauthorized' for some resources that are not found. So we can't
     * safely return '404' in all cases.
     */
    private static function dispatchException(ApiException $e, string $identifier)
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
