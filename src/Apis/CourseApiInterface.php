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

    public function setClientHandler(?object $handler);

    public function recreateCoursesCache(): void;
}
