<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service;

use Dbp\CampusonlineApi\Helpers\Pagination;
use Dbp\CampusonlineApi\LegacyWebService\Api;
use Dbp\CampusonlineApi\LegacyWebService\ApiException;
use Dbp\CampusonlineApi\LegacyWebService\Course\CourseData;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;

class CourseApi implements LoggerAwareInterface
{
    public const ALL_ITEMS = -1;

    /** @var Api */
    private $api;
    private $config;
    private $clientHandler;
    private $cachePool;
    private $cacheTTL = 0;
    private $logger;

    public function __construct()
    {
        $this->config = [];
    }

    public function setCache(?CacheItemPoolInterface $cachePool, int $ttl): void
    {
        $this->cachePool = $cachePool;
        $this->cacheTTL = $ttl;
    }

    public function setConfig(array $config): void
    {
        $this->config = $config;
    }

    public function setClientHandler(?object $handler): void
    {
        $this->clientHandler = $handler;
        if ($this->api !== null) {
            $this->api->setClientHandler($handler);
        }
    }

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
        if ($this->api !== null) {
            $this->api->setLogger($logger);
        }
    }

    /**
     * @throws ApiException
     */
    public function checkConnection()
    {
        $this->getApi()->Course()->checkConnection();
        $this->getApi()->Person()->checkConnection();
    }

    /**
     * @throws ApiException
     */
    public function getCourseById(string $identifier, array $options = []): ?CourseData
    {
        return $this->getApi()->Course()->getCourseById($identifier, $options);
    }

    /**
     * @throws ApiException
     */
    public function getCourses(int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): array
    {
        $options[Pagination::CURRENT_PAGE_NUMBER_PARAMETER_NAME] = $currentPageNumber;
        if ($maxNumItemsPerPage !== self::ALL_ITEMS) {
            $options[Pagination::MAX_NUM_ITEMS_PER_PAGE_PARAMETER_NAME] = $maxNumItemsPerPage;
        }

        return $this->getApi()->Course()->getCourses($options)->getItems();
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

        return $this->getApi()->Course()->getCoursesByOrganization($orgUnitId, $options)->getItems();
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

        return $this->getApi()->Course()->getCoursesByLecturer($personId, $options)->getItems();
    }

    /**
     * @throws ApiException
     */
    public function getStudentsByCourse(string $courseId, int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): array
    {
        $options[Pagination::CURRENT_PAGE_NUMBER_PARAMETER_NAME] = $currentPageNumber;
        if ($maxNumItemsPerPage !== self::ALL_ITEMS) {
            $options[Pagination::MAX_NUM_ITEMS_PER_PAGE_PARAMETER_NAME] = $maxNumItemsPerPage;
        }

        return $this->getApi()->Person()->getStudentsByCourse($courseId, $options)->getItems();
    }

    private function getApi(): Api
    {
        if ($this->api === null) {
            $accessToken = $this->config['api_token'] ?? '';
            $baseUrl = $this->config['api_url'] ?? '';
            $rootOrgUnitId = $this->config['org_root_id'] ?? '';

            $this->api = new Api($baseUrl, $accessToken, $rootOrgUnitId,
                $this->logger, $this->cachePool, $this->cacheTTL, $this->clientHandler);
        }

        return $this->api;
    }
}
