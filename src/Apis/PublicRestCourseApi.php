<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Apis;

use Dbp\CampusonlineApi\Helpers\ApiException;
use Dbp\CampusonlineApi\PublicRestApi\Connection;
use Dbp\CampusonlineApi\PublicRestApi\Courses\CourseApi;
use Dbp\CampusonlineApi\PublicRestApi\Courses\CourseRegistrationApi;
use Dbp\CampusonlineApi\PublicRestApi\Courses\CourseResource;
use Dbp\CampusonlineApi\PublicRestApi\Courses\LectureshipApi;
use Dbp\Relay\BaseCourseBundle\Entity\Course;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Entity\CachedCourse;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Entity\CachedCourseTitle;
use Dbp\Relay\CoreBundle\Doctrine\QueryHelper;
use Dbp\Relay\CoreBundle\Rest\Options;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterException;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterTreeBuilder;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\Nodes\ConditionNode;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\Nodes\Node;
use Dbp\Relay\CoreBundle\Rest\Query\Pagination\Pagination;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Expr\Join;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class PublicRestCourseApi implements CourseApiInterface
{
    private const DEFAULT_LANGUAGE = 'de';

    private CourseApi $courseApi;
    private ?LoggerInterface $logger;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        array $config,
        ?LoggerInterface $logger = null)
    {
        $this->courseApi = new CourseApi(
            new Connection(
                $config['base_url'],
                $config['client_id'],
                $config['client_secret']
            )
        );

        $this->logger = $logger;
        if ($this->logger !== null) {
            $this->courseApi->setLogger($logger);
        }
    }

    /**
     * @throws ApiException
     */
    public function checkConnection(): void
    {
        $this->courseApi->getCoursesBySemesterKeyCursorBased(self::getSemesterKeys()[0]);
    }

    public function setClientHandler(?object $handler): void
    {
        $this->courseApi->setClientHandler($handler);
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

        $insertIntoCoursesStagingStatement = <<<STMT
            INSERT INTO $coursesStagingTable ($uidColumn, $courseCodeColumn, $semesterKeyColumn, $courseTypeColumn, $courseIdentityCodeUidColumn)
            VALUES (:$uidColumn, :$courseCodeColumn, :$semesterKeyColumn, :$courseTypeColumn, :$courseIdentityCodeUidColumn)
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
                    $resourcePage = $this->courseApi->getCoursesBySemesterKeyCursorBased($semesterKey, $nextCursor, 1000);
                    /** @var CourseResource $courseResource */
                    foreach ($resourcePage->getResources() as $courseResource) {
                        $connection->executeStatement($insertIntoCoursesStagingStatement, [
                            $uidColumn => $courseResource->getUid(),
                            $courseCodeColumn => $courseResource->getCourseCode(),
                            $semesterKeyColumn => $courseResource->getSemesterKey(),
                            $courseTypeColumn => $courseResource->getCourseTypeKey(),
                            $courseIdentityCodeUidColumn => $courseResource->getCourseIdentityCodeUid(),
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
     * @throws ApiException
     */
    public function getCourseById(string $identifier, array $options = []): CourseAndExtraData
    {
        $cachedCourse = $this->entityManager->getRepository(CachedCourse::class)->find($identifier);
        if ($cachedCourse === null) {
            throw new ApiException('course with ID not found: '.$identifier,
                Response::HTTP_NOT_FOUND, true);
        }

        return self::createCourseAndExtraDataFromCachedCourse(
            $cachedCourse,
            $options);
    }

    /**
     * @throws ApiException
     */
    public function getCourses(int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): iterable
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
                throw new ApiException('failed to build filter for organization search');
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
                throw new ApiException('failed to combine filters for course query');
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
                throw new ApiException('failed to apply filter to course query');
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

    public function getAttendeesByCourse(string $courseId, int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): array
    {
        $courseRegistrationApi = new CourseRegistrationApi($this->courseApi->getConnection());

        $attendeeIds = [];
        foreach ($courseRegistrationApi->getCourseRegistrationsByCourseUid($courseId) as $registrationResource) {
            $attendeeIds[] = $registrationResource->getPersonUid();
        }

        return $attendeeIds;
    }

    public function getLecturersByCourse(string $courseId, int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): array
    {
        $lectureshipApi = new LectureshipApi($this->courseApi->getConnection());

        $lecturerIds = [];
        foreach ($lectureshipApi->getLectureshipsByCourseUid($courseId) as $lectureshipResource) {
            $lecturerIds[] = $lectureshipResource->getPersonUid();
        }

        return $lecturerIds;
    }

    private static function createCourseAndExtraDataFromCourseResource(CourseResource $courseResource, array $options): CourseAndExtraData
    {
        $course = new Course();
        $course->setIdentifier($courseResource->getUid());
        $course->setCode($courseResource->getCourseCode());
        if ($localizedTitle = $courseResource->getTitle()) {
            $course->setName($localizedTitle[Options::getLanguage($options) ?? self::DEFAULT_LANGUAGE] ?? $localizedTitle[0]);
        }

        return new CourseAndExtraData($course, $courseResource->getResourceData());
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
        ]);
    }

    /**
     * @return string[]
     */
    public static function getSemesterKeys(?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $month = (int) $now->format('n');
        $year = (int) $now->format('Y');

        if ($month >= 10 || $month <= 2) { // in winter
            $winterStartYear = ($month >= 10 && $month <= 12) ? $year : $year - 1;
            $summerYear = $winterStartYear + 1;

            return ["{$winterStartYear}W", "{$summerYear}S"];
        } else { // in summer
            return ["{$year}S", "{$year}W"];
        }
    }
}
