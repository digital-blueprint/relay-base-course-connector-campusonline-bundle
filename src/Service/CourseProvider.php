<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service;

use Dbp\CampusonlineApi\Helpers\ApiException;
use Dbp\CampusonlineApi\PublicRestApi\Appointments\AppointmentApi;
use Dbp\CampusonlineApi\PublicRestApi\Appointments\AppointmentResource;
use Dbp\CampusonlineApi\PublicRestApi\Connection;
use Dbp\CampusonlineApi\PublicRestApi\Courses\CourseApi;
use Dbp\CampusonlineApi\PublicRestApi\Courses\CourseDescriptionApi;
use Dbp\CampusonlineApi\PublicRestApi\Courses\CourseDescriptionResource;
use Dbp\CampusonlineApi\PublicRestApi\Courses\CourseGroupApi;
use Dbp\CampusonlineApi\PublicRestApi\Courses\CourseGroupResource;
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
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event\CourseEventPreEvent;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event\CoursePostEvent;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Event\CoursePreEvent;
use Dbp\Relay\CoreBundle\Doctrine\QueryHelper;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\LocalData\LocalDataEventDispatcher;
use Dbp\Relay\CoreBundle\Rest\Options;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\Filter;
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
    private array $config = [];
    private ?CacheItemPoolInterface $cachePool = null;
    private int $cacheTTL = 0;

    /**
     * @var string[]
     */
    private array $currentResponseCourseIdentifierCache = [];

    /**
     * array<string, array> request cache for localized type names.
     */
    private array $localizedTypeNameRequestCache = [];

    /**
     * array<string, array> request cache for localized lectureship function names.
     */
    private array $localizedLectureshipFunctionNameRequestCache = [];

    /**
     * @var array<string, CourseDescriptionResource|null>|null
     */
    private ?array $courseDescriptionRequestCache = null;

    /**
     * array<string, CourseRegistrationResource[]>|null.
     */
    private ?array $courseRegistrationsRequestCache = null;

    /**
     * array<string, LectureshipResource[]>|null.
     */
    private ?array $lectureshipRequestCache = null;

    /**
     * array<string, CourseGroupResource>|null.
     */
    private ?array $courseGroupRequestCache = null;

    private ?\DateTimeZone $eventTimeZone = null;

    /**
     * Provides the $numSemesters most recent semester keys, where the most recent one is at index 0 of the array.
     *
     * @param \DateTimeImmutable|null $now For testing purposes
     *
     * @return string[]
     */
    public static function getMostRecentSemesterKeys(int $numSemesters, ?\DateTimeImmutable $now = null): array
    {
        if ($numSemesters < 0) {
            throw new \InvalidArgumentException('number of semesters must be non-negative');
        }

        $now ??= new \DateTimeImmutable(timezone: new \DateTimeZone('UTC'));
        $currentMonth = (int) $now->format('n');
        $currentYear = (int) $now->format('Y');

        if ($currentMonth >= 10 || $currentMonth <= 2) { // in winter
            $currentSemesterYear = ($currentMonth >= 10 && $currentMonth <= 12) ? $currentYear + 1 : $currentYear;
            $currentSemesterSeason = 'S';
        } else { // in summer
            $currentSemesterYear = $currentYear;
            $currentSemesterSeason = 'W';
        }

        $semesterKeys = [];
        for ($semesterCounter = 0; $semesterCounter < $numSemesters; ++$semesterCounter) {
            $semesterKeys[] = $currentSemesterYear.$currentSemesterSeason;
            $currentSemesterYear = ($currentSemesterSeason === 'W') ? $currentSemesterYear : $currentSemesterYear - 1;
            $currentSemesterSeason = ($currentSemesterSeason === 'W') ? 'S' : 'W';
        }

        return $semesterKeys;
    }

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly EventDispatcherInterface $eventDispatcher)
    {
    }

    /**
     * Just for unit testing.
     */
    public function reset(): void
    {
        $this->courseApi = null;
        $this->currentResponseCourseIdentifierCache = [];
        $this->localizedLectureshipFunctionNameRequestCache = [];
        $this->localizedTypeNameRequestCache = [];
        $this->courseDescriptionRequestCache = null;
        $this->courseRegistrationsRequestCache = null;
        $this->lectureshipRequestCache = null;
        $this->courseGroupRequestCache = null;
    }

    /**
     * @throws \DateInvalidTimeZoneException
     */
    public function setConfig(array $config): void
    {
        $this->config = $config;
        $this->eventTimeZone = new \DateTimeZone(
            $this->config[Configuration::CAMPUS_ONLINE_NODE][Configuration::EVENT_TIME_ZONE_NODE]);
    }

    private function getEventTimeZone(): \DateTimeZone
    {
        assert($this->eventTimeZone !== null);

        return $this->eventTimeZone;
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
        $this->getCourseApi()->getCoursesBySemesterKeyCursorBased(self::getMostRecentSemesterKeys(1)[0]);
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
        $numSemestersToGet = $this->config[Configuration::NUM_SEMESTERS_TO_PROVIDE];
        try {
            foreach (self::getMostRecentSemesterKeys($numSemestersToGet) as $semesterKey) {
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
    public function getCourseById(string $identifier, array $options = []): Course
    {
        $options = $this->preProcessCourseOptions($options);

        try {
            $filter = FilterTreeBuilder::create()
                ->equals('identifier', $identifier)
                ->createFilter();
        } catch (FilterException $filterException) {
            $this->logger->error('failed to build filter for course identifier query: '.$filterException->getMessage(), [$filterException]);
            throw new \RuntimeException('failed to build filter for person identifier query');
        }

        $courses = $this->getCoursesInternal(1, 2, $filter, $options);
        $numCourses = count($courses);
        if ($numCourses === 0) {
            throw ApiError::withDetails(Response::HTTP_NOT_FOUND,
                sprintf("course with identifier '%s' could not be found!", $identifier));
        } elseif ($numCourses > 1) {
            throw new \RuntimeException(sprintf("multiple persons found for identifier '%s'", $identifier));
        }

        return $courses[0];
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
        $options = $this->preProcessCourseOptions($options);

        $filter = null;
        if ($searchTerm = $options[Course::SEARCH_PARAMETER_NAME] ?? null) {
            try {
                $CACHED_COURSE_ENTITY_ALIAS = 'c';
                $CACHED_COURSE_TITLE_ENTITY_ALIAS = 't';
                $filterTreeBuilder = FilterTreeBuilder::create();
                // ALL search terms must be contained either in the course code or the course title (in the specified language)
                foreach (explode(' ', $searchTerm) as $term) {
                    $filterTreeBuilder
                        ->or()
                            ->iContains($CACHED_COURSE_ENTITY_ALIAS.'.'.CachedCourse::COURSE_CODE_COLUMN_NAME, $term)
                            ->and()
                                ->iContains($CACHED_COURSE_TITLE_ENTITY_ALIAS.'.'.CachedCourseTitle::TITLE_COLUMN_NAME,
                                    $term)
                                ->equals($CACHED_COURSE_TITLE_ENTITY_ALIAS.'.'.CachedCourseTitle::LANGUAGE_TAG_COLUMN_NAME,
                                    Options::getLanguage($options) ?? self::DEFAULT_LANGUAGE_TAG)
                            ->end() // end and
                        ->end(); // end or
                }
                $filter = $filterTreeBuilder->createFilter();
            } catch (FilterException $filterException) {
                $this->dispatchException($filterException, 'failed to build filter for course search: '.$filterException->getMessage());
            }
        }

        return $this->getCoursesInternal($currentPageNumber, $maxNumItemsPerPage, $filter, $options);
    }

    public function getCourseEventById(string $identifier, array $options = []): CourseEvent
    {
        $options = $this->preProcessCourseEventOptions($options);

        try {
            $courseAppointmentApi = new AppointmentApi($this->getCourseApi()->getConnection());
            $appointmentResource = $courseAppointmentApi->getAppointmentByIdentifier($identifier);
            $courseEvent = $this->createCourseEventFromAppointmentResource($appointmentResource);

            return $this->postProcessCourseEvent(
                new CourseEventAndExtraData($courseEvent, $appointmentResource->getResourceData()), $options);
        } catch (ApiException $apiException) {
            throw $this->dispatchException($apiException, 'failed to get course event');
        }
    }

    public function getCourseEventsByCourseId(string $courseIdentifier,
        int $currentPageNumber, int $maxNumItemsPerPage, array $filters = [], array $options = []): array
    {
        $options = $this->preProcessCourseEventOptions($options);

        try {
            $appointmentApi = new AppointmentApi($this->getCourseApi()->getConnection());

            $nextCursor = null;
            if ($currentPageNumber !== 1) {
                // first, get the initial page to obtain the cursor -> only works if maxNumItems doesn't exceed the allowed maximum
                // TODO: handle case when maxNumItems exceeds allowed maximum -> generalize page-based <-> cursor-based pagination
                $resourcePage = $appointmentApi->getAppointmentsByCourseUidCursorBased(
                    $courseIdentifier,
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
                    $courseIdentifier,
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
            throw $this->dispatchException($apiException, 'failed to get course events');
        }
    }

    /**
     * @param string|null $registrationStatus if specified, only attendees with the given registration status are returned, otherwise all attendees are returned
     *
     * @return array<int, array<string, mixed>>
     *
     * @throws ApiError
     */
    public function getAttendeesByCourse(
        string $courseIdentifier,
        ?string $registrationStatus = CourseRegistrationResource::REGISTRATION_STATUS_FIXED): array
    {
        $attendeeIds = [];
        foreach ($this->getCourseRegistrationResourcesCached($courseIdentifier) as $registrationResource) {
            if ($registrationStatus === null
                || $registrationStatus === $registrationResource->getRegistrationStatus()) {
                $attendeeIds[] = [
                    'personIdentifier' => $registrationResource->getPersonUid(),
                ];
            }
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
            throw $this->dispatchException($apiException);
        }
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function getCourseGroupsByCourse(string $courseIdentifier, array $options = []): array
    {
        try {
            $ATTENDEE_PERSON_IDENTIFIERS_KEY = 'attendeeIdentifiers';
            $LECTURER_PERSON_IDENTIFIERS_KEY = 'lecturerIdentifiers';

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
                switch ($registrationResource->getRegistrationStatus()) {
                    case CourseRegistrationResource::REGISTRATION_STATUS_FIXED:
                        $courseGroupPeopleMap[$courseGroupIdentifier][$ATTENDEE_PERSON_IDENTIFIERS_KEY][] = $registrationResource->getPersonUid();
                        break;
                }
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
            foreach ($this->getCourseGroupResourcesCached($courseIdentifier) as $courseGroupResource) {
                $courseGroups[] = [
                    'identifier' => $courseGroupResource->getUid(),
                    'name' => $courseGroupResource->getName(Options::getLanguage($options) ?? self::DEFAULT_LANGUAGE_TAG),
                    $LECTURER_PERSON_IDENTIFIERS_KEY => $courseGroupPeopleMap[$courseGroupResource->getUid()][$LECTURER_PERSON_IDENTIFIERS_KEY] ?? [],
                    /*
                     * @deprecated Replaced by course group registrations. Left for backward compatibility.
                     */
                    $ATTENDEE_PERSON_IDENTIFIERS_KEY => $courseGroupPeopleMap[$courseGroupResource->getUid()][$ATTENDEE_PERSON_IDENTIFIERS_KEY] ?? [],
                ];
            }

            return $courseGroups;
        } catch (ApiException $apiException) {
            throw $this->dispatchException($apiException);
        }
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function getCourseGroupRegistrationsByCourse(string $courseIdentifier): array
    {
        try {
            $ATTENDEE_PERSON_IDENTIFIERS_KEY = 'attendeeIdentifiers';
            $ATTENDEE_WAITING_LIST_PERSON_IDENTIFIERS_KEY = 'attendeeWaitingListIdentifiers';

            $courseGroupPeopleMap = [];
            foreach ($this->getCourseRegistrationResourcesCached($courseIdentifier) as $registrationResource) {
                $courseGroupIdentifier = $registrationResource->getCourseGroupUid();
                if (null === ($courseGroupPeopleMap[$courseGroupIdentifier] ?? null)) {
                    $courseGroupPeople = [
                        $ATTENDEE_PERSON_IDENTIFIERS_KEY => [],
                        $ATTENDEE_WAITING_LIST_PERSON_IDENTIFIERS_KEY => [],
                    ];
                    $courseGroupPeopleMap[$courseGroupIdentifier] = $courseGroupPeople;
                }
                switch ($registrationResource->getRegistrationStatus()) {
                    case CourseRegistrationResource::REGISTRATION_STATUS_FIXED:
                        $courseGroupPeopleMap[$courseGroupIdentifier][$ATTENDEE_PERSON_IDENTIFIERS_KEY][] = $registrationResource->getPersonUid();
                        break;
                    case CourseRegistrationResource::REGISTRATION_STATUS_WAITING_LIST:
                        $courseGroupPeopleMap[$courseGroupIdentifier][$ATTENDEE_WAITING_LIST_PERSON_IDENTIFIERS_KEY][] = $registrationResource->getPersonUid();
                        break;
                }
            }

            $courseGroups = [];
            foreach ($this->getCourseGroupResourcesCached($courseIdentifier) as $courseGroupResource) {
                $courseGroups[] = [
                    'identifier' => $courseGroupResource->getUid(),
                    $ATTENDEE_PERSON_IDENTIFIERS_KEY => $courseGroupPeopleMap[$courseGroupResource->getUid()][$ATTENDEE_PERSON_IDENTIFIERS_KEY] ?? [],
                    $ATTENDEE_WAITING_LIST_PERSON_IDENTIFIERS_KEY => $courseGroupPeopleMap[$courseGroupResource->getUid()][$ATTENDEE_WAITING_LIST_PERSON_IDENTIFIERS_KEY] ?? [],
                ];
            }

            return $courseGroups;
        } catch (ApiException $apiException) {
            throw $this->dispatchException($apiException);
        }
    }

    public function getDescriptionByCourse(string $courseIdentifier, array $options = []): ?string
    {
        try {
            return $this->getCourseDescriptionResourceCached($courseIdentifier)
            ?->getContentLocalized(Options::getLanguage($options) ?? self::DEFAULT_LANGUAGE_TAG);
        } catch (ApiException $apiException) {
            throw $this->dispatchException($apiException);
        }
    }

    public function getObjectiveByCourse(string $courseIdentifier, array $options = []): ?string
    {
        try {
            return $this->getCourseDescriptionResourceCached($courseIdentifier)
                ?->getObjectiveLocalized(Options::getLanguage($options) ?? self::DEFAULT_LANGUAGE_TAG);
        } catch (ApiException $apiException) {
            throw $this->dispatchException($apiException);
        }
    }

    public function getTeachingMethodKeyByCourse(string $courseIdentifier): ?string
    {
        try {
            return $this->getCourseDescriptionResourceCached($courseIdentifier)
                ?->getTeachingMethodKey();
        } catch (ApiException $apiException) {
            throw $this->dispatchException($apiException);
        }
    }

    public function getTeachingMethodDescriptionByCourse(string $courseIdentifier, array $options = []): ?string
    {
        try {
            return $this->getCourseDescriptionResourceCached($courseIdentifier)
                ?->getTeachingMethodDescriptionLocalized(Options::getLanguage($options) ?? self::DEFAULT_LANGUAGE_TAG);
        } catch (ApiException $apiException) {
            throw $this->dispatchException($apiException);
        }
    }

    public function getExpectedPreviousKnowledgeByCourse(string $courseIdentifier, array $options = []): ?string
    {
        try {
            return $this->getCourseDescriptionResourceCached($courseIdentifier)
                ?->getExpectedPreviousKnowledgeLocalized(Options::getLanguage($options) ?? self::DEFAULT_LANGUAGE_TAG);
        } catch (ApiException $apiException) {
            throw $this->dispatchException($apiException);
        }
    }

    public function getLocalizedTypeNameByKey(string $key, array $options = []): ?string
    {
        if ($this->localizedTypeNameRequestCache === []) {
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
        if ($this->localizedLectureshipFunctionNameRequestCache === []) {
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
        $url = $this->config[Configuration::CAMPUS_ONLINE_NODE][Configuration::BASE_URL_NODE] ?? '';
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
                    $this->config[Configuration::CAMPUS_ONLINE_NODE][Configuration::BASE_URL_NODE] ?? '',
                    $this->config[Configuration::CAMPUS_ONLINE_NODE][Configuration::CLIENT_ID_NODE] ?? '',
                    $this->config[Configuration::CAMPUS_ONLINE_NODE][Configuration::CLIENT_SECRET_NODE] ?? ''
                )
            );
            $this->courseApi->setLogger($this->logger);
        }

        return $this->courseApi;
    }

    /**
     * @returns iterable<CourseAndExtraData>
     */
    public function getCoursesInternal(int $currentPageNumber, int $maxNumItemsPerPage, ?Filter $filter, array $options = []): iterable
    {
        $courseAndExtraData = $this->getCoursesFromCache(
            Pagination::getFirstItemIndex($currentPageNumber, $maxNumItemsPerPage),
            $maxNumItemsPerPage,
            $filter,
            $options
        );

        // NOTE: post-processing is done after all persons of been collected, so that API requests for additional data (e.g. person claims)
        // can be optimized by caching results for the whole page instead of doing individual requests for each person during post-processing
        return array_map(
            fn (CourseAndExtraData $courseAndExtraData) => $this->postProcessCourse($courseAndExtraData, $options),
            $courseAndExtraData
        );
    }

    /**
     * @returns iterable<CourseAndExtraData>
     */
    private function getCoursesFromCache(int $firstItemIndex, int $maxNumItems, ?Filter $filter, array $options = []): iterable
    {
        $CACHED_COURSE_ENTITY_ALIAS = 'c';
        $CACHED_COURSE_TITLE_ENTITY_ALIAS = 't';

        try {
            $combinedFilter = FilterTreeBuilder::create()->createFilter();
            if ($filter) {
                $combinedFilter->combineWith($filter);
            }
            if ($filterFromOptions = Options::getFilter($options)) {
                $combinedFilter->combineWith($filterFromOptions);
            }

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

            $combinedFilter->mapConditionNodes(
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

            $queryBuilder = $this->entityManager->createQueryBuilder();
            $queryBuilder
                ->select($CACHED_COURSE_ENTITY_ALIAS)
                ->from(CachedCourse::class, $CACHED_COURSE_ENTITY_ALIAS)
                ->innerJoin(CachedCourseTitle::class, $CACHED_COURSE_TITLE_ENTITY_ALIAS, Join::WITH,
                    $CACHED_COURSE_ENTITY_ALIAS.'.'.CachedCourse::UID_COLUMN_NAME." = $CACHED_COURSE_TITLE_ENTITY_ALIAS.course");

            QueryHelper::addFilter($queryBuilder, $combinedFilter);

            $paginator = new Paginator($queryBuilder->getQuery());
            $paginator->getQuery()
                ->setFirstResult($firstItemIndex)
                ->setMaxResults($maxNumItems);

            $courseAndExtraDataPage = [];
            /** @var CachedCourse $cachedCourse */
            foreach ($paginator as $cachedCourse) {
                $this->currentResponseCourseIdentifierCache[] = $cachedCourse->getUid();
                $courseAndExtraDataPage[] = self::createCourseAndExtraDataFromCachedCourse($cachedCourse, $options);
            }

            return $courseAndExtraDataPage;
        } catch (\Throwable $throwable) {
            throw $this->dispatchException($throwable, 'failed to get courses from cache');
        }
    }

    private function postProcessCourse(CourseAndExtraData $courseAndExtraData, array $options): Course
    {
        $eventDispatcher = new LocalDataEventDispatcher('', $this->eventDispatcher);
        $eventDispatcher->onNewOperation($options);

        $postEvent = new CoursePostEvent(
            $courseAndExtraData->getCourse(), $courseAndExtraData->getExtraData(), $this, $options);
        $eventDispatcher->dispatch($postEvent);

        return $courseAndExtraData->getCourse();
    }

    private function postProcessCourseEvent(CourseEventAndExtraData $courseEventAndExtraData, array $options): CourseEvent
    {
        $eventDispatcher = new LocalDataEventDispatcher('', $this->eventDispatcher);
        $eventDispatcher->onNewOperation($options);

        $postEvent = new CourseEventPostEvent(
            $courseEventAndExtraData->getCourseEvent(), $courseEventAndExtraData->getExtraData(), $this, $options);
        $eventDispatcher->dispatch($postEvent);

        return $courseEventAndExtraData->getCourseEvent();
    }

    public function createCourseEventFromAppointmentResource(AppointmentResource $appointmentResource): CourseEvent
    {
        $courseEvent = new CourseEvent();
        $courseEvent->setIdentifier($appointmentResource->getUid());
        $courseEvent->setCourseIdentifier($appointmentResource->getCourseUid());
        if (($eventStart = $appointmentResource->getStartAt()) !== null) {
            $courseEvent->setStartAt(new \DateTimeImmutable($eventStart, $this->getEventTimeZone()));
        }
        if (($eventEnd = $appointmentResource->getEndAt()) !== null) {
            $courseEvent->setEndAt(new \DateTimeImmutable($eventEnd, $this->getEventTimeZone()));
        }

        $courseEvent->setTypeKey(
            match ($appointmentResource->getEventTypeKey()) {
                AppointmentResource::REGULAR_CLASS_EVENT_TYPE_KEY => CourseEvent::CLASS_TYPE_KEY,
                AppointmentResource::EXAM_EVENT_TYPE_KEY => CourseEvent::EXAM_TYPE_KEY,
                default => null,
            });

        return $courseEvent;
    }

    private function getCourseDescriptionResourceCached(string $courseIdentifier): ?CourseDescriptionResource
    {
        $courseIdsToGetDescriptionFor = [];
        if (null === $this->courseDescriptionRequestCache) {
            // descriptions of current course results have not been cached yey
            $courseIdsToGetDescriptionFor = array_merge([$courseIdentifier], $this->currentResponseCourseIdentifierCache);
            $this->courseDescriptionRequestCache = array_fill_keys($courseIdsToGetDescriptionFor, null);
        } elseif (false === array_key_exists($courseIdentifier, $this->courseDescriptionRequestCache)) {
            // requested course is not part of the current course results
            $courseIdsToGetDescriptionFor[] = $courseIdentifier;
            $this->courseDescriptionRequestCache[$courseIdentifier] = null;
        }

        if ($courseIdsToGetDescriptionFor !== []) {
            $courseDescriptionApi = new CourseDescriptionApi($this->getCourseApi()->getConnection());
            try {
                foreach ($courseDescriptionApi->getCourseDescriptionsFor($courseIdsToGetDescriptionFor) as $courseDescriptionResource) {
                    // NOTE: courses and their respective descriptions have the same id
                    $this->courseDescriptionRequestCache[$courseDescriptionResource->getUid()] = $courseDescriptionResource;
                }
            } catch (ApiException $apiException) {
                throw $this->dispatchException($apiException, 'failed to get course descriptions for course with id '.$courseIdentifier);
            }
        }

        return $this->courseDescriptionRequestCache[$courseIdentifier];
    }

    /**
     * @return CourseRegistrationResource[]
     */
    private function getCourseRegistrationResourcesCached(string $courseIdentifier): array
    {
        $courseIdsToGetRegistrationsFor = [];
        if (null === $this->courseRegistrationsRequestCache) {
            // registrations of current course results have not been cached yey
            $courseIdsToGetRegistrationsFor = array_merge([$courseIdentifier], $this->currentResponseCourseIdentifierCache);
            $this->courseRegistrationsRequestCache = array_fill_keys($courseIdsToGetRegistrationsFor, []);
        } elseif (false === array_key_exists($courseIdentifier, $this->courseRegistrationsRequestCache)) {
            // requested course is not part of the current course results
            $courseIdsToGetRegistrationsFor = [$courseIdentifier];
            $this->courseRegistrationsRequestCache[$courseIdentifier] = [];
        }

        if ([] !== $courseIdsToGetRegistrationsFor) {
            $courseRegistrationApi = new CourseRegistrationApi($this->getConnection());
            try {
                foreach ($courseRegistrationApi->getCourseRegistrationsFor($courseIdsToGetRegistrationsFor) as $courseRegistrationResource) {
                    $this->courseRegistrationsRequestCache[$courseRegistrationResource->getCourseUid()][] = $courseRegistrationResource;
                }
            } catch (ApiException $apiException) {
                throw $this->dispatchException($apiException, 'failed to get course registrations for course with id '.$courseIdentifier);
            }
        }

        return $this->courseRegistrationsRequestCache[$courseIdentifier];
    }

    /**
     * @return LectureshipResource[]
     */
    private function getLectureshipResourcesCached(string $courseIdentifier): array
    {
        $courseIdsToGetLectureshipsFor = [];
        if (null === $this->lectureshipRequestCache) {
            // lectureships of current course results have not been cached yey
            $courseIdsToGetLectureshipsFor = array_merge([$courseIdentifier], $this->currentResponseCourseIdentifierCache);
            $this->lectureshipRequestCache = array_fill_keys($courseIdsToGetLectureshipsFor, []);
        } elseif (false === array_key_exists($courseIdentifier, $this->lectureshipRequestCache)) {
            // requested course is not part of the current course results
            $courseIdsToGetLectureshipsFor = [$courseIdentifier];
            $this->lectureshipRequestCache[$courseIdentifier] = [];
        }

        if ([] !== $courseIdsToGetLectureshipsFor) {
            $lectureshipApi = new LectureshipApi($this->getConnection());
            try {
                foreach ($lectureshipApi->getLectureshipsFor($courseIdsToGetLectureshipsFor) as $lectureshipResource) {
                    $this->lectureshipRequestCache[$lectureshipResource->getCourseUid()][] = $lectureshipResource;
                }
            } catch (ApiException $apiException) {
                throw $this->dispatchException($apiException, 'failed to get lectureships for course with id '.$courseIdentifier);
            }
        }

        return $this->lectureshipRequestCache[$courseIdentifier];
    }

    /**
     * @return CourseGroupResource[]
     */
    private function getCourseGroupResourcesCached(string $courseIdentifier): array
    {
        $courseIdsToGetCourseGroupsFor = [];
        if (null === $this->courseGroupRequestCache) {
            // course groups of current course results have not been cached yey
            $courseIdsToGetCourseGroupsFor = array_merge([$courseIdentifier], $this->currentResponseCourseIdentifierCache);
            $this->courseGroupRequestCache = array_fill_keys($courseIdsToGetCourseGroupsFor, []);
        } elseif (false === array_key_exists($courseIdentifier, $this->courseGroupRequestCache)) {
            // requested course is not part of the current course results
            $courseIdsToGetCourseGroupsFor = [$courseIdentifier];
            $this->courseGroupRequestCache[$courseIdentifier] = [];
        }

        if ([] !== $courseIdsToGetCourseGroupsFor) {
            $courseGroupApi = new CourseGroupApi($this->getConnection());
            try {
                foreach ($courseGroupApi->getCourseGroupsFor($courseIdsToGetCourseGroupsFor) as $courseGroupResource) {
                    $this->courseGroupRequestCache[$courseGroupResource->getCourseUid()][] = $courseGroupResource;
                }
            } catch (ApiException $apiException) {
                throw $this->dispatchException($apiException, 'failed to get course groups for course with id '.$courseIdentifier);
            }
        }

        return $this->courseGroupRequestCache[$courseIdentifier];
    }

    private function getConnection(): Connection
    {
        return $this->getCourseApi()->getConnection();
    }

    private function preProcessCourseOptions(array $options): array
    {
        $preEvent = new CoursePreEvent($options);
        $this->eventDispatcher->dispatch($preEvent);

        return $preEvent->getOptions();
    }

    private function preProcessCourseEventOptions(array $options): array
    {
        $preEvent = new CourseEventPreEvent($options);
        $this->eventDispatcher->dispatch($preEvent);

        return $preEvent->getOptions();
    }

    /**
     * @throws ApiError
     */
    private function dispatchException(\Throwable $throwable, ?string $logMessage = null): ApiError
    {
        $apiError = null;
        if ($throwable instanceof ApiException) {
            if ($throwable->isHttpResponseCode()) {
                if ($throwable->getCode() === Response::HTTP_NOT_FOUND) {
                    $apiError = new ApiError(Response::HTTP_NOT_FOUND, sprintf('item not be found'));
                    $logMessage = null; // don't log 404s
                } elseif ($throwable->getCode() >= 500) {
                    $apiError = new ApiError(Response::HTTP_BAD_GATEWAY, 'failed to get item(s)');
                }
            }
        }
        if (null !== $logMessage) {
            $this->logger->error($logMessage.': '.$throwable->getMessage(), [$throwable]);
        }

        return $apiError ?? new ApiError(Response::HTTP_INTERNAL_SERVER_ERROR, 'failed to get item(s)');
    }

    private static function createCourseAndExtraDataFromCachedCourse(CachedCourse $cachedCourse, array $options): CourseAndExtraData
    {
        $course = new Course();
        $course->setIdentifier($cachedCourse->getUid());
        $course->setCode($cachedCourse->getCourseCode());
        foreach ($cachedCourse->getTitles() as $cachedTitle) {
            if ($cachedTitle->getLanguageTag() === (Options::getLanguage($options) ?? self::DEFAULT_LANGUAGE_TAG)) {
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
}
