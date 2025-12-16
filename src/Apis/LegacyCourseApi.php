<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Apis;

use Dbp\CampusonlineApi\Helpers\Filters;
use Dbp\CampusonlineApi\Helpers\Pagination;
use Dbp\CampusonlineApi\LegacyWebService\Api;
use Dbp\CampusonlineApi\LegacyWebService\ApiException;
use Dbp\CampusonlineApi\LegacyWebService\Course\CourseData;
use Dbp\CampusonlineApi\LegacyWebService\Person\PersonData;
use Dbp\CampusonlineApi\LegacyWebService\ResourceApi;
use Dbp\CampusonlineApi\LegacyWebService\ResourceData;
use Dbp\Relay\BaseCourseBundle\Entity\Course;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerAwareTrait;
use Psr\Log\LoggerInterface;

class LegacyCourseApi implements CourseApiInterface
{
    use LoggerAwareTrait;
    public const ALL_ITEMS = -1;

    private Api $api;

    public function __construct(array $config, ?CacheItemPoolInterface $cachePool = null, int $cacheTTL = 0,
        ?LoggerInterface $logger = null)
    {
        $baseUrl = $config['api_url'] ?? '';
        $accessToken = $config['api_token'] ?? '';
        $rootOrgUnitId = $config['org_root_id'] ?? '';

        $this->api = new Api($baseUrl, $accessToken, $rootOrgUnitId,
            $logger, $cachePool, $cacheTTL);
    }

    /**
     * @throws ApiException
     */
    public function checkConnection(): void
    {
        $this->api->Course()->checkConnection();
        $this->api->Person()->checkConnection();
    }

    public function setClientHandler(?object $handler): void
    {
        $this->api->setClientHandler($handler);
    }

    public function recreateCoursesCache(): void
    {
    }

    /**
     * @throws ApiException
     */
    public function getCourseById(string $identifier, array $options = []): CourseAndExtraData
    {
        return self::createCourseAndExtraDataFromCourseData(
            $this->api->Course()->getCourseById($identifier, $options));
    }

    /**
     * @throws ApiException
     */
    public function getCourses(int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): iterable
    {
        self::addFilterOptions($options);

        $options[Pagination::CURRENT_PAGE_NUMBER_PARAMETER_NAME] = $currentPageNumber;
        if ($maxNumItemsPerPage !== self::ALL_ITEMS) {
            $options[Pagination::MAX_NUM_ITEMS_PER_PAGE_PARAMETER_NAME] = $maxNumItemsPerPage;
        }

        foreach ($this->api->Course()->getCourses($options)->getItems() as $courseData) {
            yield self::createCourseAndExtraDataFromCourseData($courseData);
        }
    }

    /**
     * @throws ApiException
     */
    public function getCoursesByOrganization(string $orgUnitId, int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): array
    {
        $options[Pagination::CURRENT_PAGE_NUMBER_PARAMETER_NAME] = $currentPageNumber;
        if ($maxNumItemsPerPage !== self::ALL_ITEMS) {
            $options[Pagination::MAX_NUM_ITEMS_PER_PAGE_PARAMETER_NAME] = $maxNumItemsPerPage;
        }

        return $this->api->Course()->getCoursesByOrganization($orgUnitId, $options)->getItems();
    }

    /**
     * @throws ApiException
     */
    public function getCoursesByLecturer(string $personId, int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): array
    {
        $options[Pagination::CURRENT_PAGE_NUMBER_PARAMETER_NAME] = $currentPageNumber;
        if ($maxNumItemsPerPage !== self::ALL_ITEMS) {
            $options[Pagination::MAX_NUM_ITEMS_PER_PAGE_PARAMETER_NAME] = $maxNumItemsPerPage;
        }

        return $this->api->Course()->getCoursesByLecturer($personId, $options)->getItems();
    }

    /**
     * @return string[]
     *
     * @throws \Dbp\CampusonlineApi\Helpers\ApiException
     */
    public function getAttendeesByCourse(string $courseId, int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): array
    {
        $options[Pagination::CURRENT_PAGE_NUMBER_PARAMETER_NAME] = $currentPageNumber;
        if ($maxNumItemsPerPage !== self::ALL_ITEMS) {
            $options[Pagination::MAX_NUM_ITEMS_PER_PAGE_PARAMETER_NAME] = $maxNumItemsPerPage;
        }

        return array_map(function (PersonData $personData): string {
            return $personData->getIdentifier();
        }, $this->api->Person()->getStudentsByCourse($courseId, $options)->getItems());
    }

    /**
     * @return string[]
     *
     * @throws \Dbp\CampusonlineApi\Helpers\ApiException
     */
    public function getLecturersByCourse(string $courseId, int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): array
    {
        $courseData = $this->api->Course()->getCourseById($courseId, $options);

        return array_map(fn (CourseData $cd) => $cd[ResourceData::IDENTIFIER_ATTRIBUTE], $courseData->getContacts() ?? []);
    }

    private static function createCourseAndExtraDataFromCourseData(CourseData $courseData): CourseAndExtraData
    {
        $course = new Course();
        $course->setIdentifier($courseData->getIdentifier());
        $course->setCode($courseData->getCode());
        $course->setName($courseData->getName());

        return new CourseAndExtraData($course, $courseData->getData());
    }

    private static function addFilterOptions(array &$options): void
    {
        if (($searchParameter = $options[Course::SEARCH_PARAMETER_NAME] ?? null) && $searchParameter !== '') {
            unset($options[Course::SEARCH_PARAMETER_NAME]);

            ResourceApi::addFilter($options, CourseData::NAME_ATTRIBUTE,
                Filters::CONTAINS_CI_OPERATOR, $searchParameter, Filters::LOGICAL_OR_OPERATOR);
            ResourceApi::addFilter($options, CourseData::CODE_ATTRIBUTE,
                Filters::CONTAINS_CI_OPERATOR, $searchParameter, Filters::LOGICAL_OR_OPERATOR);
        }
    }
}
