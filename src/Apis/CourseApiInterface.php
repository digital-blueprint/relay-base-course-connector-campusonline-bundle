<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Apis;

use Dbp\CampusonlineApi\Helpers\ApiException;

interface CourseApiInterface
{
    /**
     * @throws ApiException
     */
    public function checkConnection(): void;

    public function setClientHandler(?object $handler);

    /**
     * @throws ApiException
     */
    public function getCourseById(string $identifier, array $options = []): CourseAndExtraData;

    /**
     * @return iterable<CourseAndExtraData>
     *
     * @throws ApiException
     */
    public function getCourses(int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): iterable;

    /**
     * @return string[]
     *
     * @throws ApiException
     */
    public function getAttendeesByCourse(string $courseId, int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): array;

    /**
     * @return string[]
     *
     * @throws ApiException
     */
    public function getLecturersByCourse(string $courseId, int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): array;

    /**
     * Public REST API only.
     */
    public function recreateCoursesCache(): void;
}
