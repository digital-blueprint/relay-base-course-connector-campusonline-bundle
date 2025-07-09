<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service;

use Dbp\CampusonlineApi\LegacyWebService\ApiException;
use Dbp\Relay\BaseCourseBundle\API\CourseProviderInterface;
use Dbp\Relay\BaseCourseBundle\Entity\Course;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Apis\CourseAndExtraData;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Apis\CourseApiInterface;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Apis\LegacyCourseApi;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Apis\PublicRestCourseApi;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\DependencyInjection\Configuration;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event\CoursePostEvent;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event\CoursePreEvent;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\LocalData\LocalDataEventDispatcher;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;

class CourseProvider implements CourseProviderInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private ?CourseApiInterface $courseApi = null;
    private LocalDataEventDispatcher $eventDispatcher;
    private array $config = [];
    private ?object $clientHandler = null;
    private ?CacheItemPoolInterface $cachePool = null;
    private int $cacheTTL = 0;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        EventDispatcherInterface $eventDispatcher)
    {
        $this->eventDispatcher = new LocalDataEventDispatcher(Course::class, $eventDispatcher);
    }

    public function setCache(?CacheItemPoolInterface $cachePool, int $ttl): void
    {
        $this->cachePool = $cachePool;
        $this->cacheTTL = $ttl;
    }

    public function setConfig(array $config): void
    {
        $this->config = $config[Configuration::CAMPUS_ONLINE_NODE];
    }

    /**
     * @internal
     *
     * Just for unit testing
     */
    public function setClientHandler(?object $handler): void
    {
        $this->clientHandler = $handler;
        if ($this->courseApi !== null) {
            $this->courseApi->setClientHandler($handler);
        }
    }

    /**
     * @throws ApiException
     */
    public function checkConnection(): void
    {
        $this->getCourseApi()->checkConnection();
    }

    public function recreateCoursesCache(): void
    {
        $this->getCourseApi()->recreateCoursesCache();
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
        try {
            $courseAndExtraData = $this->getCourseApi()->getCourseById($identifier, $options);
        } catch (ApiException $apiException) {
            throw self::dispatchException($apiException, $identifier);
        }

        return $this->postProcessCourse($courseAndExtraData);
    }

    /**
     * @param array $options Available options:
     *                       * Locale::LANGUAGE_OPTION (language in ISO 639‑1 format)
     *                       * Course::SEARCH_PARAMETER_NAME (partial, case-insensitive text search on 'name' attribute)
     *                       * LocalData::INCLUDE_PARAMETER_NAME
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
        $courses = [];

        try {
            foreach ($this->getCourseApi()->getCourses($currentPageNumber, $maxNumItemsPerPage, $options) as $courseAndExtraData) {
                $courses[] = $this->postProcessCourse($courseAndExtraData);
            }
        } catch (ApiException $apiException) {
            throw self::dispatchException($apiException);
        }

        return $courses;
    }

    public function getAttendeesByCourseId(string $courseId, int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): array
    {
        return [];
    }

    private function getCourseApi(): CourseApiInterface
    {
        if ($this->courseApi === null) {
            if ($this->config['legacy'] ?? true) {
                $this->courseApi = new LegacyCourseApi($this->config, $this->cachePool, $this->cacheTTL, $this->clientHandler, $this->logger);
            } else {
                $this->courseApi = new PublicRestCourseApi($this->entityManager, $this->config, $this->clientHandler);
            }
        }

        return $this->courseApi;
    }

    private function postProcessCourse(CourseAndExtraData $courseAndExtraData): Course
    {
        $postEvent = new CoursePostEvent(
            $courseAndExtraData->getCourse(), $courseAndExtraData->getExtraData(), $this);
        $this->eventDispatcher->dispatch($postEvent);

        return $courseAndExtraData->getCourse();
    }

    /**
     * NOTE: Campusonline returns '401 unauthorized' for some resources that are not found. So we can't
     * safely return '404' in all cases because '401' is also returned by CO if e.g. the token is not valid.
     *
     * @throws ApiError
     * @throws ApiException
     */
    private static function dispatchException(ApiException $apiException, ?string $identifier = null): ApiError
    {
        if ($apiException->isHttpResponseCode()) {
            switch ($apiException->getCode()) {
                case Response::HTTP_NOT_FOUND:
                    if ($identifier !== null) {
                        return new ApiError(Response::HTTP_NOT_FOUND, sprintf("Id '%s' could not be found!", $identifier));
                    }
                    break;
                case Response::HTTP_UNAUTHORIZED:
                    return new ApiError(Response::HTTP_UNAUTHORIZED, sprintf("Id '%s' could not be found or access denied!", $identifier));
            }
            if ($apiException->getCode() >= 500) {
                return new ApiError(Response::HTTP_BAD_GATEWAY, 'failed to get organizations from Campusonline');
            }
        }

        return new ApiError(Response::HTTP_INTERNAL_SERVER_ERROR, 'failed to get course(s): '.$apiException->getMessage());
    }
}
