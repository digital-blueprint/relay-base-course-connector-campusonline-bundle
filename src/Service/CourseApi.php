<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service;

use Dbp\CampusonlineApi\LegacyWebService\Api;
use Dbp\CampusonlineApi\LegacyWebService\ApiException;
use Dbp\CampusonlineApi\LegacyWebService\Course\CourseData;
use Dbp\CampusonlineApi\LegacyWebService\Person\PersonData;
use Dbp\Relay\BaseCourseBundle\Entity\Course;
use Dbp\Relay\BaseCourseBundle\Entity\CourseAttendee;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;

class CourseApi implements LoggerAwareInterface
{
    /*
     * @var Api
     */
    private $api;
    private $config;
    private $clientHandler;
    private $cachePool;
    private $cacheTTL;
    private $logger;

    public function __construct()
    {
        $this->config = [];
    }

    public function setCache(?CacheItemPoolInterface $cachePool, int $ttl): void
    {
        $this->cachePool = $cachePool;
        $this->cacheTTL = $ttl;
        if ($this->api !== null) {
            $this->api->setCache($cachePool, $ttl);
        }
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
        $this->getApi()->Course()->getCourses();
    }

    /**
     * @throws ApiException
     */
    public function getCourseById(string $identifier, array $options = []): ?Course
    {
        $courseData = $this->getApi()->Course()->getCourseById($identifier, $options);

        $course = null;
        if ($courseData !== null) {
            $course = self::createCourseFromCourseData($courseData);
        }

        return $course;
    }

    /**
     * @return Course[]
     *
     * @throws ApiException
     */
    public function getCourses(array $options = []): array
    {
        $courses = [];
        foreach ($this->getApi()->Course()->getCourses($options) as $courseData) {
            $courses[] = self::createCourseFromCourseData($courseData);
        }

        return $courses;
    }

    /**
     * @return Course[]
     *
     * @throws ApiException
     */
    public function getCoursesByOrganization(string $orgUnitId, array $options = []): array
    {
        $courses = [];
        foreach ($this->getApi()->Course()->getCoursesByOrganization($orgUnitId, $options) as $courseData) {
            $courses[] = self::createCourseFromCourseData($courseData);
        }

        return $courses;
    }

    /**
     * @return Course[]
     */
    public function getCoursesByPerson(string $personId, array $options = []): array
    {
        $courses = [];
        foreach ($this->getApi()->Course()->getCoursesByPerson($personId, $options) as $courseData) {
            $courses[] = self::createCourseFromCourseData($courseData);
        }

        return $courses;
    }

    /**
     * @return CourseAttendee[]
     */
    public function getAttendeesByCourse(string $courseId, array $options = []): array
    {
        $attendees = [];
        foreach ($this->getApi()->Course()->getStudentsByCourse($courseId, $options) as $personData) {
            $attendees[] = self::createCourseAttendeeFromPersonData($personData);
        }

        return $attendees;
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

    private static function createCourseFromCourseData(CourseData $courseData): Course
    {
        $course = new Course();
        $course->setIdentifier($courseData->getIdentifier());
        $course->setName($courseData->getName());
        $course->setDescription($courseData->getDescription());
        $course->setType($courseData->getType());

        return $course;
    }

    private static function createCourseAttendeeFromPersonData(PersonData $personData): CourseAttendee
    {
        $attendee = new CourseAttendee();
        // note: CO person ID is not the same as LDAP person ID,
        // which is normally used as identifier in base Person entity
        $attendee->setIdentifier($personData->getIdentifier());
        $attendee->setGivenName($personData->getGivenName());
        $attendee->setFamilyName($personData->getFamilyName());
        $attendee->setEmail($personData->getEmail());

        return $attendee;
    }
}
