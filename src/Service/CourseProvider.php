<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service;

use Dbp\Relay\BaseCourseBundle\API\CourseProviderInterface;
use Dbp\Relay\BaseCourseBundle\Entity\Course;
use Dbp\Relay\BaseCourseBundle\Entity\CourseAttendee;
use Dbp\Relay\CoreBundle\Exception\ApiError;

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
        try {
            return $this->courseApi->getCourseById($identifier, $options);
        } catch (\Exception $e) {
            throw new ApiError($e->getCode(), $e->getMessage());
        }
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
        } catch (\Exception $e) {
            throw new ApiError($e->getCode(), $e->getMessage());
        }
    }

    /*
    * @return Course[]
     *
     * @throws ApiError
    */
    public function getCoursesByOrganization(string $orgUnitId, array $options = []): array
    {
        try {
            return $this->courseApi->getCoursesByOrganization($orgUnitId, $options);
        } catch (\Exception $e) {
            throw new ApiError($e->getCode(), $e->getMessage());
        }
    }

    /**
     * @return Course[]
     */
    public function getCoursesByPerson(string $personId, array $options = []): array
    {
        try {
            return $this->courseApi->getCoursesByPerson($personId, $options);
        } catch (\Exception $e) {
            throw new ApiError($e->getCode(), $e->getMessage());
        }
    }

    /**
     * @return CourseAttendee[]
     */
    public function getAttendeesByCourse(string $courseId, array $options = []): array
    {
        try {
            return $this->courseApi->getAttendeesByCourse($courseId, $options);
        } catch (\Exception $e) {
            throw new ApiError($e->getCode(), $e->getMessage());
        }
    }
}
