<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service;

use Dbp\CampusonlineApi\LegacyWebService\ApiException;
use Dbp\CampusonlineApi\PublicRestApi\Appointments\AppointmentApi;
use Dbp\CampusonlineApi\PublicRestApi\Appointments\AppointmentResource;
use Dbp\CampusonlineApi\PublicRestApi\Connection;
use Dbp\CampusonlineApi\PublicRestApi\Courses\CourseApi;
use Dbp\CampusonlineApi\PublicRestApi\Courses\CourseDescriptionApi;
use Dbp\CampusonlineApi\PublicRestApi\Courses\CourseDescriptionResource;
use Dbp\CampusonlineApi\PublicRestApi\Courses\CourseGroupApi;
use Dbp\CampusonlineApi\PublicRestApi\Courses\CourseRegistrationApi;
use Dbp\CampusonlineApi\PublicRestApi\Courses\CourseRegistrationResource;
use Dbp\CampusonlineApi\PublicRestApi\Courses\CourseResource;
use Dbp\CampusonlineApi\PublicRestApi\Courses\CourseTypeApi;
use Dbp\CampusonlineApi\PublicRestApi\Courses\LectureshipApi;
use Dbp\CampusonlineApi\PublicRestApi\Courses\LectureshipFunctionsApi;
use Dbp\CampusonlineApi\PublicRestApi\Courses\LectureshipResource;
use Dbp\Relay\BaseCourseBundle\API\CourseProviderInterface;
use Dbp\Relay\BaseCourseBundle\Entity\Course;
use Dbp\Relay\BaseCourseBundle\Entity\CourseEvent;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\DependencyInjection\Configuration;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Entity\CachedCourse;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Entity\CachedCourseTitle;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event\CourseEventPostEvent;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event\CoursePostEvent;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event\CoursePreEvent;
use Dbp\Relay\CoreBundle\Doctrine\QueryHelper;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\LocalData\LocalDataEventDispatcher;
use Dbp\Relay\CoreBundle\Rest\Options;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterException;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterTreeBuilder;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\Nodes\ConditionNode;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\Nodes\Node;
use Dbp\Relay\CoreBundle\Rest\Query\Pagination\Pagination;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Response;

class CourseProvider implements CourseProviderInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const DEFAULT_LANGUAGE_TAG = 'de';

    private ?CourseApi $courseApi = null;
    private LocalDataEventDispatcher $eventDispatcher;
    private array $config = [];
    private ?CacheItemPoolInterface $cachePool = null;
    private int $cacheTTL = 0;

    /**
     * array<string, array> request cache for localized type names.
     */
    private ?array $localizedTypeNameRequestCache = null;

    /**
     * array<string, array> request cache for localized lectureship function names.
     */
    private ?array $localizedLectureshipFunctionNameRequestCache = null;

    /**
     * array<string, CourseRegistrationResource>.
     */
    private array $courseRegistrationsRequestCache = [];

    /**
     * array<string, LectureshipResource>.
     */
    private array $lectureshipRequestCache = [];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        EventDispatcherInterface $eventDispatcher)
    {
        $this->eventDispatcher = new LocalDataEventDispatcher('', $eventDispatcher);
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
        $this->getCourseApi()->setClientHandler($handler);
    }

    /**
     * @throws ApiException
     */
    public function checkConnection(): void
    {
        $this->getCourseApi()->getCoursesBySemesterKeyCursorBased(self::getSemesterKeys()[0]);
    }

    /**
     * @throws \Throwable
     */
    public function recreateCoursesCache(): void
    {
        $coursesStagingTable = CachedCourse::STAGING_TABLE_NAME;
        $uidColumn = CachedCourse::UID_COLUMN_NAME;
        $courseCodeColumn = CachedCourse::COURSE_CODE_COLUMN_NAME;
        $semesterKeyColumn = CachedCourse::SEMESTER_KEY_COLUMN_NAME;
        $courseTypeColumn = CachedCourse::COURSE_TYPE_KEY_COLUMN_NAME;
        $courseIdentityCodeUidColumn = CachedCourse::COURSE_IDENTITY_CODE_UID_COLUMN_NAME;
        $organizationUidColumn = CachedCourse::ORGANIZATION_UID_COLUMN_NAME;
        $semesterHoursColumn = CachedCourse::SEMESTER_HOURS_COLUMN_NAME;
        $mainLanguageOfInstructionColumn = CachedCourse::MAIN_LANGUAGE_OF_INSTRUCTION_COLUMN_NAME;

        $insertIntoCoursesStagingStatement = <<<STMT
            INSERT INTO $coursesStagingTable 
                ($uidColumn, $courseCodeColumn, $semesterKeyColumn, $courseTypeColumn, $courseIdentityCodeUidColumn,
                 $organizationUidColumn, $semesterHoursColumn, $mainLanguageOfInstructionColumn)
            VALUES (:$uidColumn, :$courseCodeColumn, :$semesterKeyColumn, :$courseTypeColumn, :$courseIdentityCodeUidColumn,
                    :$organizationUidColumn, :$semesterHoursColumn, :$mainLanguageOfInstructionColumn)
            STMT;

        $courseTitlesStagingTable = CachedCourseTitle::STAGING_TABLE_NAME;
        $courseUidColumn = CachedCourseTitle::COURSE_UID_COLUMN_NAME;
        $languageTagColumn = CachedCourseTitle::LANGUAGE_TAG_COLUMN_NAME;
        $titleColumn = CachedCourseTitle::TITLE_COLUMN_NAME;

        $insertIntoCourseTitlesStagingStatement = <<<STMT
                INSERT INTO $courseTitlesStagingTable ($courseUidColumn, $languageTagColumn, $titleColumn)
                VALUES (:$courseUidColumn, :$languageTagColumn, :$titleColumn)
            STMT;

        $connection = $this->entityManager->getConnection();
        try {
            foreach (self::getSemesterKeys() as $semesterKey) {
                $nextCursor = null;
                do {
                    $resourcePage = $this->getCourseApi()->getCoursesBySemesterKeyCursorBased($semesterKey, $nextCursor, 1000);
                    /** @var CourseResource $courseResource */
                    foreach ($resourcePage->getResources() as $courseResource) {
                        $connection->executeStatement($insertIntoCoursesStagingStatement, [
                            $uidColumn => $courseResource->getUid(),
                            $courseCodeColumn => $courseResource->getCourseCode(),
                            $semesterKeyColumn => $courseResource->getSemesterKey(),
                            $courseTypeColumn => $courseResource->getCourseTypeKey(),
                            $courseIdentityCodeUidColumn => $courseResource->getCourseIdentityCodeUid(),
                            $organizationUidColumn => $courseResource->getOrganisationUid(),
                            $semesterHoursColumn => $courseResource->getSemesterHours(),
                            $mainLanguageOfInstructionColumn => $courseResource->getMainLanguageOfInstruction(),
                        ]);

                        foreach ($courseResource->getTitle() as $languageTag => $title) {
                            $connection->executeStatement($insertIntoCourseTitlesStagingStatement, [
                                $courseUidColumn => $courseResource->getUid(),
                                $languageTagColumn => $languageTag,
                                $titleColumn => $title,
                            ]);
                        }
                    }
                    $nextCursor = $resourcePage->getNextCursor();
                } while ($nextCursor !== null);
            }

            $coursesLiveTable = CachedCourse::TABLE_NAME;
            $coursesTempTable = 'courses_old';
            $courseTitlesLiveTable = CachedCourseTitle::TABLE_NAME;
            $courseTitlesTempTable = 'course_titles_old';

            // swap live and staging tables:
            $connection->executeStatement(<<<STMT
                RENAME TABLE
                $coursesLiveTable TO $coursesTempTable,
                $coursesStagingTable TO $coursesLiveTable,
                $coursesTempTable TO $coursesStagingTable,
                $courseTitlesLiveTable TO $courseTitlesTempTable,
                $courseTitlesStagingTable TO $courseTitlesLiveTable,
                $courseTitlesTempTable TO $courseTitlesStagingTable
                STMT);
        } catch (\Throwable $throwable) {
            $this->logger->error('failed to recreate courses cache: '.$throwable->getMessage(), [$throwable]);
            throw $throwable;
        } finally {
            $connection->executeStatement("TRUNCATE TABLE $coursesStagingTable");
            $connection->executeStatement("TRUNCATE TABLE $courseTitlesStagingTable");
        }
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
            $cachedCourse = $this->entityManager->getRepository(CachedCourse::class)->find($identifier);
            if ($cachedCourse === null) {
                throw new ApiError(Response::HTTP_NOT_FOUND, 'course with ID not found: '.$identifier);
            }
        } catch (ApiException $apiException) {
            throw self::dispatchException($apiException, $identifier);
        }

        return $this->postProcessCourse(
            self::createCourseAndExtraDataFromCachedCourse($cachedCourse, $options), $options
        );
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
            foreach ($this->getCoursesInternal($currentPageNumber, $maxNumItemsPerPage, $options) as $courseAndExtraData) {
                $courses[] = $this->postProcessCourse($courseAndExtraData, $options);
            }
        } catch (ApiException $apiException) {
            throw self::dispatchException($apiException);
        }

        return $courses;
    }

    public function getCourseEventById(string $identifier, array $options = []): CourseEvent
    {
        $this->eventDispatcher->onNewOperation($options);

        try {
            $courseAppointmentApi = new AppointmentApi($this->getCourseApi()->getConnection());
            $appointmentResource = $courseAppointmentApi->getAppointmentByIdentifier($identifier);
            $courseEvent = $this->createCourseEventFromAppointmentResource($appointmentResource);

            return $this->postProcessCourseEvent(
                new CourseEventAndExtraData($courseEvent, $appointmentResource->getResourceData()), $options);
        } catch (ApiException $apiException) {
            throw self::dispatchException($apiException);
        }
    }

    public function getCourseEvents(int $currentPageNumber, int $maxNumItemsPerPage, array $filters = [], array $options = []): array
    {
        $this->eventDispatcher->onNewOperation($options);

        try {
            $appointmentApi = new AppointmentApi($this->getCourseApi()->getConnection());

            $nextCursor = null;
            if ($currentPageNumber !== 1) {
                // first, get the initial page to obtain the cursor -> only works if maxNumItems doesn't exceed the allowed maximum
                // TODO: handle case when maxNumItems exceeds allowed maximum -> generalize page-based <-> cursor-based pagination
                $resourcePage = $appointmentApi->getAppointmentsByCourseUidCursorBased(
                    $filters[CourseEvent::COURSE_IDENTIFIER_QUERY_PARAMETER],
                    maxNumItems: Pagination::getFirstItemIndex($currentPageNumber, $maxNumItemsPerPage));
                $nextCursor = $resourcePage->getNextCursor();
            }

            $typeKeyQueryParameter = match ($filters[CourseEvent::TYPE_KEY_QUERY_PARAMETER] ?? null) {
                CourseEvent::CLASS_TYPE_KEY => AppointmentResource::REGULAR_CLASS_EVENT_TYPE_KEY,
                CourseEvent::EXAM_TYPE_KEY => AppointmentResource::EXAM_EVENT_TYPE_KEY,
                null => null,
            };

            $courseEvents = [];
            do {
                $resourcesAndCursor = $appointmentApi->getAppointmentsByCourseUidCursorBased(
                    $filters[CourseEvent::COURSE_IDENTIFIER_QUERY_PARAMETER],
                    cursor: $nextCursor,
                    maxNumItems: $typeKeyQueryParameter !== null ? 1000 : $maxNumItemsPerPage);

                /** @var AppointmentResource $appointmentResource */
                foreach ($resourcesAndCursor->getResources() as $appointmentResource) {
                    if ($typeKeyQueryParameter !== null && $appointmentResource->getEventTypeKey() !== $typeKeyQueryParameter) {
                        continue;
                    }
                    $courseEvent = $this->createCourseEventFromAppointmentResource($appointmentResource);
                    $courseEvents[] = $this->postProcessCourseEvent(
                        new CourseEventAndExtraData($courseEvent, $appointmentResource->getResourceData()), $options);
                }
                $nextCursor = $resourcesAndCursor->getNextCursor();
            } while ($nextCursor !== null && count($courseEvents) < $maxNumItemsPerPage);

            return $courseEvents;
        } catch (ApiException $apiException) {
            throw self::dispatchException($apiException);
        }
    }

    /**
     * @param array $options Available options:
     *                       * Locale::LANGUAGE_OPTION (language in ISO 639‑1 format)
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ApiError
     */
    public function getAttendeesByCourse(string $courseIdentifier, array $options = []): array
    {
        $attendeeIds = [];
        foreach ($this->getCourseRegistrationResourcesCached($courseIdentifier) as $registrationResource) {
            $attendeeIds[] = [
                'personIdentifier' => $registrationResource->getPersonUid(),
            ];
        }

        return $attendeeIds;
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws ApiError
     */
    public function getLecturersByCourse(string $courseIdentifier, array $options = []): array
    {
        try {
            $lecturerIds = [];
            foreach ($this->getLectureshipResourcesCached($courseIdentifier) as $lectureshipResource) {
                $functionKey = $lectureshipResource->getFunctionKey();
                $lecturerIds[] = [
                    'personIdentifier' => $lectureshipResource->getPersonUid(),
                    'functionKey' => $functionKey,
                    'functionName' => $this->getLocalizedLectureshipFunctionNameByKey($functionKey, $options),
                ];
            }

            return $lecturerIds;
        } catch (ApiException $apiException) {
            throw self::dispatchException($apiException);
        }
    }

    /**
     * @return array<array>
     */
    public function getGroupsByCourse(string $courseIdentifier, array $options = []): array
    {
        try {
            $courseGroupApi = new CourseGroupApi($this->getCourseApi()->getConnection());

            $ATTENDEE_PERSON_IDENTIFIERS_KEY = 'attendeePersonIdentifiers';
            $LECTURER_PERSON_IDENTIFIERS_KEY = 'lecturerPersonIdentifiers';

            $courseGroupPeopleMap = [];
            foreach ($this->getCourseRegistrationResourcesCached($courseIdentifier) as $registrationResource) {
                $courseGroupIdentifier = $registrationResource->getCourseGroupUid();
                if (null === ($courseGroupPeopleMap[$courseGroupIdentifier] ?? null)) {
                    $courseGroupPeople = [
                        $ATTENDEE_PERSON_IDENTIFIERS_KEY => [],
                        $LECTURER_PERSON_IDENTIFIERS_KEY => [],
                    ];
                    $courseGroupPeopleMap[$courseGroupIdentifier] = $courseGroupPeople;
                }
                $courseGroupPeopleMap[$courseGroupIdentifier][$ATTENDEE_PERSON_IDENTIFIERS_KEY][] = $registrationResource->getPersonUid();
            }
            foreach ($this->getLectureshipResourcesCached($courseIdentifier) as $lectureshipResource) {
                foreach ($lectureshipResource->getGroupUids() as $courseGroupIdentifier) {
                    if (null === ($courseGroupPeopleMap[$courseGroupIdentifier] ?? null)) {
                        $courseGroupPeople = [
                            $ATTENDEE_PERSON_IDENTIFIERS_KEY => [],
                            $LECTURER_PERSON_IDENTIFIERS_KEY => [],
                        ];
                        $courseGroupPeopleMap[$courseGroupIdentifier] = $courseGroupPeople;
                    }
                    $courseGroupPeopleMap[$courseGroupIdentifier][$LECTURER_PERSON_IDENTIFIERS_KEY][] = $lectureshipResource->getPersonUid();
                }
            }

            $courseGroups = [];
            foreach ($courseGroupApi->getCourseGroupsByCourseUid($courseIdentifier) as $courseGroupPeople) {
                $courseGroups[] = [
                    'identifier' => $courseGroupPeople->getUid(),
                    'name' => $courseGroupPeople->getName(Options::getLanguage($options) ?? self::DEFAULT_LANGUAGE_TAG),
                    $ATTENDEE_PERSON_IDENTIFIERS_KEY =>
                        $courseGroupPeopleMap[$courseGroupPeople->getUid()][$ATTENDEE_PERSON_IDENTIFIERS_KEY] ?? [],
                    $LECTURER_PERSON_IDENTIFIERS_KEY =>
                        $courseGroupPeopleMap[$courseGroupPeople->getUid()][$LECTURER_PERSON_IDENTIFIERS_KEY] ?? [],
                ];
            }

            return $courseGroups;
        } catch (ApiException $apiException) {
            throw self::dispatchException($apiException);
        }
    }

    public function getDescriptionByCourse(string $courseIdentifier, array $options = []): ?string
    {
        try {
            return $this->getFirstOrNullDescriptionResource($courseIdentifier)
            ?->getContent(Options::getLanguage($options) ?? self::DEFAULT_LANGUAGE_TAG);
        } catch (ApiException $apiException) {
            throw self::dispatchException($apiException);
        }
    }

    public function getObjectiveByCourse(string $courseIdentifier, array $options = []): ?string
    {
        try {
            return $this->getFirstOrNullDescriptionResource($courseIdentifier)
                ?->getObjective(Options::getLanguage($options) ?? self::DEFAULT_LANGUAGE_TAG);
        } catch (ApiException $apiException) {
            throw self::dispatchException($apiException);
        }
    }

    public function getLocalizedTypeNameByKey(string $key, array $options = []): ?string
    {
        if ($this->localizedTypeNameRequestCache === null) {
            $courseTypeApi = new CourseTypeApi($this->getCourseApi()->getConnection());
            foreach ($courseTypeApi->getCourseTypes() as $courseTypeResource) {
                if (($currentKey = $courseTypeResource->getKey()) && ($currentName = $courseTypeResource->getName())) {
                    $this->localizedTypeNameRequestCache[$currentKey] = $currentName;
                }
            }
        }

        return $this->localizedTypeNameRequestCache[$key][Options::getLanguage($options) ?? self::DEFAULT_LANGUAGE_TAG] ?? null;
    }

    public function getLocalizedLectureshipFunctionNameByKey(string $key, array $options = []): ?string
    {
        if ($this->localizedLectureshipFunctionNameRequestCache === null) {
            $lectureshipFunctionsApi = new LectureshipFunctionsApi($this->getCourseApi()->getConnection());
            foreach ($lectureshipFunctionsApi->getLectureshipFunctions() as $lectureshipFunction) {
                if (($currentKey = $lectureshipFunction->getKey()) && ($currentName = $lectureshipFunction->getName())) {
                    $this->localizedLectureshipFunctionNameRequestCache[$currentKey] = $currentName;
                }
            }
        }

        return $this->localizedLectureshipFunctionNameRequestCache[$key][Options::getLanguage($options) ?? self::DEFAULT_LANGUAGE_TAG] ?? null;
    }

    /**
     * @return string Url with trailing slash
     */
    public function getCampusOnlineWebBaseUrl(): string
    {
        $url = $this->config[Configuration::BASE_URL_NODE] ?? '';
        if ($url) {
            $url = rtrim($url, '/');
            $lastSlashPos = strrpos($url, '/');
            if ($lastSlashPos !== false) {
                $url = substr($url, 0, $lastSlashPos + 1);
            }
        }

        return $url;
    }

    private function getCourseApi(): CourseApi
    {
        if ($this->courseApi === null) {
            $this->courseApi = new CourseApi(
                new Connection(
                    $this->config['base_url'],
                    $this->config['client_id'],
                    $this->config['client_secret']
                )
            );
        }

        return $this->courseApi;
    }

    /**
     * @returns iterable<CourseAndExtraData>
     */
    public function getCoursesInternal(int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): iterable
    {
        $CACHED_COURSE_ENTITY_ALIAS = 'c';
        $CACHED_COURSE_TITLE_ENTITY_ALIAS = 't';

        $combinedFilter = null;
        if ($searchTerm = $options[Course::SEARCH_PARAMETER_NAME] ?? null) {
            try {
                $combinedFilter = FilterTreeBuilder::create()
                    ->or()
                    ->and()
                    ->iContains($CACHED_COURSE_TITLE_ENTITY_ALIAS.'.'.CachedCourseTitle::TITLE_COLUMN_NAME,
                        $searchTerm)
                    ->equals($CACHED_COURSE_TITLE_ENTITY_ALIAS.'.'.CachedCourseTitle::LANGUAGE_TAG_COLUMN_NAME,
                        Options::getLanguage($options))
                    ->end()
                    ->iContains($CACHED_COURSE_ENTITY_ALIAS.'.'.CachedCourse::COURSE_CODE_COLUMN_NAME, $searchTerm)
                    ->end()
                    ->createFilter();
            } catch (FilterException $filterException) {
                $this->logger->error('failed to build filter for organization search: '.$filterException->getMessage(), [$filterException]);
                throw new \RuntimeException('failed to build filter for organization search');
            }
        }

        if ($filter = Options::getFilter($options)) {
            $pathMapping = [
                'identifier' => $CACHED_COURSE_ENTITY_ALIAS.'.'.CachedCourse::UID_COLUMN_NAME,
                'code' => $CACHED_COURSE_ENTITY_ALIAS.'.'.CachedCourse::COURSE_CODE_COLUMN_NAME,
            ];
            foreach (CachedCourse::ALL_COLUMN_NAMES as $columnName) {
                $pathMapping[$columnName] = $CACHED_COURSE_ENTITY_ALIAS.'.'.$columnName;
            }
            foreach (CachedCourseTitle::ALL_COLUMN_NAMES as $columnName) {
                $pathMapping[$columnName] = $CACHED_COURSE_TITLE_ENTITY_ALIAS.'.'.$columnName;
            }

            $filter->mapConditionNodes(
                function (ConditionNode $node) use ($pathMapping, $CACHED_COURSE_TITLE_ENTITY_ALIAS, $options): Node {
                    if (isset($pathMapping[$node->getPath()])) {
                        $node->setPath($pathMapping[$node->getPath()]);
                    } elseif ($node->getPath() === 'name') {
                        $node->setPath($CACHED_COURSE_TITLE_ENTITY_ALIAS.'.'.CachedCourseTitle::TITLE_COLUMN_NAME);
                        $node = FilterTreeBuilder::create()
                            ->appendChild($node)
                            ->equals($CACHED_COURSE_TITLE_ENTITY_ALIAS.'.'.CachedCourseTitle::LANGUAGE_TAG_COLUMN_NAME,
                                Options::getLanguage($options))
                            ->createFilter()->getRootNode();
                    }

                    return $node;
                });

            try {
                $combinedFilter = $combinedFilter ?
                    $combinedFilter->combineWith($filter) : $filter;
            } catch (FilterException $filterException) {
                $this->logger->error('failed to combine filters for course query: '.$filterException->getMessage(), [$filterException]);
                throw new \RuntimeException('failed to combine filters for course query');
            }
        }

        $queryBuilder = $this->entityManager->createQueryBuilder();
        $queryBuilder
            ->select($CACHED_COURSE_ENTITY_ALIAS)
            ->from(CachedCourse::class, $CACHED_COURSE_ENTITY_ALIAS)
            ->innerJoin(CachedCourseTitle::class, $CACHED_COURSE_TITLE_ENTITY_ALIAS, Join::WITH,
                $CACHED_COURSE_ENTITY_ALIAS.'.'.CachedCourse::UID_COLUMN_NAME." = $CACHED_COURSE_TITLE_ENTITY_ALIAS.course");

        if ($combinedFilter !== null) {
            try {
                QueryHelper::addFilter($queryBuilder, $combinedFilter);
            } catch (\Exception $exception) {
                $this->logger->error('failed to apply filter to course query: '.$exception->getMessage(), [$exception]);
                throw new \RuntimeException('failed to apply filter to course query');
            }
        }

        $paginator = new Paginator($queryBuilder->getQuery());
        $paginator->getQuery()
            ->setFirstResult(Pagination::getFirstItemIndex($currentPageNumber, $maxNumItemsPerPage))
            ->setMaxResults($maxNumItemsPerPage);

        /** @var CachedCourse $cachedCourse */
        foreach ($paginator as $cachedCourse) {
            yield self::createCourseAndExtraDataFromCachedCourse($cachedCourse, $options);
        }
    }

    private function postProcessCourse(CourseAndExtraData $courseAndExtraData, array $options): Course
    {
        $postEvent = new CoursePostEvent(
            $courseAndExtraData->getCourse(), $courseAndExtraData->getExtraData(), $this, $options);
        $this->eventDispatcher->dispatch($postEvent);

        return $courseAndExtraData->getCourse();
    }

    private function postProcessCourseEvent(CourseEventAndExtraData $courseEventAndExtraData, array $options): CourseEvent
    {
        $postEvent = new CourseEventPostEvent(
            $courseEventAndExtraData->getCourseEvent(), $courseEventAndExtraData->getExtraData(), $this, $options);
        $this->eventDispatcher->dispatch($postEvent);

        return $courseEventAndExtraData->getCourseEvent();
    }

    private function createCourseEventFromAppointmentResource(AppointmentResource $appointmentResource): CourseEvent
    {
        $courseEvent = new CourseEvent();
        $courseEvent->setIdentifier($appointmentResource->getUid());
        $courseEvent->setCourseIdentifier($appointmentResource->getCourseUid());
        if (($eventStart = $appointmentResource->getStartAt()) !== null) {
            try {
                $courseEvent->setStartAt(new \DateTimeImmutable($eventStart));
            } catch (\Exception) {
            }
        }
        if (($eventEnd = $appointmentResource->getEndAt()) !== null) {
            try {
                $courseEvent->setEndAt(new \DateTimeImmutable($eventEnd));
            } catch (\Exception) {
            }
        }

        $courseEvent->setTypeKey(
            match ($appointmentResource->getEventTypeKey()) {
                AppointmentResource::REGULAR_CLASS_EVENT_TYPE_KEY => CourseEvent::CLASS_TYPE_KEY,
                AppointmentResource::EXAM_EVENT_TYPE_KEY => CourseEvent::EXAM_TYPE_KEY,
                default => null,
            });

        return $courseEvent;
    }

    private function getFirstOrNullDescriptionResource(string $courseIdentifier): ?CourseDescriptionResource
    {
        $courseDescriptionApi = new CourseDescriptionApi($this->getCourseApi()->getConnection());
        /** @var CourseDescriptionResource $courseDescription */
        $courseDescription = iterator_to_array(
            $courseDescriptionApi->getCourseDescriptionsByCourseUid($courseIdentifier)
        )[0] ?? null;

        return $courseDescription;
    }

    /**
     * @return string[]
     */
    public static function getSemesterKeys(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $currentMonth = (int) $now->format('n');
        $currentYear = (int) $now->format('Y');

        if ($currentMonth >= 10 || $currentMonth <= 2) { // in winter
            $winterStartYear = ($currentMonth >= 10 && $currentMonth <= 12) ? $currentYear : $currentYear - 1;
            $summerYear = $winterStartYear + 1;
            // $yearBefore = $winterStartYear - 1;
            $semesterKeys = [/* "{$yearBefore}W", */ "{$winterStartYear}S", "{$winterStartYear}W", "{$summerYear}S"];
        } else { // in summer
            $yearBefore = $currentYear - 1;
            $semesterKeys = [/* "{$yearBefore}S", */ "{$yearBefore}W", "{$currentYear}S", "{$currentYear}W"];
        }

        return $semesterKeys;
    }

    private static function createCourseAndExtraDataFromCachedCourse(CachedCourse $cachedCourse, array $options): CourseAndExtraData
    {
        $course = new Course();
        $course->setIdentifier($cachedCourse->getUid());
        $course->setCode($cachedCourse->getCourseCode());
        foreach ($cachedCourse->getTitles() as $cachedTitle) {
            if ($cachedTitle->getLanguageTag() === Options::getLanguage($options)) {
                $course->setName($cachedTitle->getTitle());
            }
        }

        return new CourseAndExtraData($course, [
            CachedCourse::COURSE_TYPE_KEY_COLUMN_NAME => $cachedCourse->getCourseTypeKey(),
            CachedCourse::SEMESTER_KEY_COLUMN_NAME => $cachedCourse->getSemesterKey(),
            CachedCourse::COURSE_IDENTITY_CODE_UID_COLUMN_NAME => $cachedCourse->getCourseIdentityCodeUid(),
            CachedCourse::ORGANIZATION_UID_COLUMN_NAME => $cachedCourse->getOrganizationUid(),
            CachedCourse::SEMESTER_HOURS_COLUMN_NAME => $cachedCourse->getSemesterHours(),
            CachedCourse::MAIN_LANGUAGE_OF_INSTRUCTION_COLUMN_NAME => $cachedCourse->getMainLanguageOfInstruction(),
        ]);
    }

    private static function createCourseAndExtraDataFromCourseResource(CourseResource $courseResource, array $options): CourseAndExtraData
    {
        $course = new Course();
        $course->setIdentifier($courseResource->getUid());
        $course->setCode($courseResource->getCourseCode());
        if ($localizedTitle = $courseResource->getTitle()) {
            $course->setName($localizedTitle[Options::getLanguage($options) ?? self::DEFAULT_LANGUAGE_TAG] ?? $localizedTitle[0]);
        }

        return new CourseAndExtraData($course, $courseResource->getResourceData());
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

    /**
     * @return CourseRegistrationResource[]
     */
    private function getCourseRegistrationResourcesCached(string $courseId): array
    {
        if (null === ($courseRegistrationResources = $this->courseRegistrationsRequestCache[$courseId] ?? null)) {
            $courseRegistrationApi = new CourseRegistrationApi($this->getConnection());
            try {
                $courseRegistrationResources = iterator_to_array($courseRegistrationApi->getCourseRegistrationsByCourseUid($courseId));
                $this->courseRegistrationsRequestCache[$courseId] = $courseRegistrationResources;
            } catch (ApiException $apiException) {
                throw self::dispatchException($apiException);
            }
        }

        return $courseRegistrationResources;
    }

    /**
     * @return LectureshipResource[]
     */
    private function getLectureshipResourcesCached(string $courseIdentifier): array
    {
        if (null === ($lectureshipResources = $this->lectureshipRequestCache[$courseIdentifier] ?? null)) {
            $lectureshipApi = new LectureshipApi($this->getConnection());
            try {
                $lectureshipResources = iterator_to_array($lectureshipApi->getLectureshipsByCourseUid($courseIdentifier));
                $this->lectureshipRequestCache[$courseIdentifier] = $lectureshipResources;
            } catch (ApiException $apiException) {
                throw self::dispatchException($apiException);
            }
        }

        return $lectureshipResources;
    }

    private function getConnection(): Connection
    {
        return $this->getCourseApi()->getConnection();
    }
}
