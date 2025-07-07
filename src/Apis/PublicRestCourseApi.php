<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Apis;

use Dbp\CampusonlineApi\LegacyWebService\ApiException;
use Dbp\CampusonlineApi\PublicRestApi\Courses\CourseApi;
use Dbp\CampusonlineApi\PublicRestApi\Courses\CourseResource;
use Dbp\Relay\BaseCourseBundle\Entity\Course;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Entity\CachedCourse;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Entity\CachedCourseTitle;
use Dbp\Relay\CoreBundle\Doctrine\QueryHelper;
use Dbp\Relay\CoreBundle\LocalData\LocalData;
use Dbp\Relay\CoreBundle\Rest\Options;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterTools;
use Doctrine\ORM\EntityManagerInterface;

class PublicRestCourseApi implements CourseApiInterface
{
    private const DEFAULT_LANGUAGE = 'de';

    private CourseApi $courseApi;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        array $config, ?object $clientHandler = null)
    {
        $this->courseApi = new CourseApi(
            $config['base_url'], $config['client_id'], $config['client_secret']);

        if ($clientHandler !== null) {
            $this->courseApi->setClientHandler($clientHandler);
        }
    }

    /**
     * @throws ApiException
     */
    public function checkConnection(): void
    {
        $this->courseApi->getCourses('2025W');
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
        $uidColumn = CachedCourse::UID_COLUMN;
        $courseCodeColumn = CachedCourse::COURSE_CODE_COLUMN;
        $semesterKeyColumn = CachedCourse::SEMESTER_KEY_COLUMN;
        $courseTypeColumn = CachedCourse::COURSE_TYPE_KEY_COLUMN;

        $courseUidColumn = CachedCourseTitle::COURSE_UID_COLUMN_NAME;
        $languageTagColumn = CachedCourseTitle::LANGUAGE_TAG_COLUMN_NAME;
        $titleColumn = CachedCourseTitle::TITLE_COLUMN_NAME;

        $insertIntoCoursesStatement = <<<STMT
            INSERT INTO courses ($uidColumn, $courseCodeColumn, $semesterKeyColumn, $courseTypeColumn)
            VALUES (:$uidColumn, :$courseCodeColumn, :$semesterKeyColumn, :$courseTypeColumn)
            STMT;

        $insertIntoCourseTitlesStatement = <<<STMT
                INSERT INTO course_titles ($courseUidColumn, $languageTagColumn, $titleColumn)
                VALUES (:$courseUidColumn, :$languageTagColumn, :$titleColumn)
            STMT;

        $connection = $this->entityManager->getConnection();
        try {
            $connection->beginTransaction();

            $connection->executeStatement('DELETE FROM courses');
            $connection->executeStatement('DELETE FROM course_titles');

            $nextCursor = null;
            do {
                $courseApiResponse = $this->courseApi->getCourses('2025W', $nextCursor, 1000);
                /** @var CourseResource $courseResource */
                foreach ($courseApiResponse->getCourseResources() as $courseResource) {
                    $connection->executeStatement($insertIntoCoursesStatement, [
                        $uidColumn => $courseResource->getUid(),
                        $courseCodeColumn => $courseResource->getCourseCode(),
                        $semesterKeyColumn => $courseResource->getSemesterKey(),
                        $courseTypeColumn => $courseResource->getCourseTypeKey(),
                    ]);

                    foreach ($courseResource->getTitle() as $languageTag => $title) {
                        $connection->executeStatement($insertIntoCourseTitlesStatement, [
                            $courseUidColumn => $courseResource->getUid(),
                            $languageTagColumn => $languageTag,
                            $titleColumn => $title,
                        ]);
                    }
                }
                $nextCursor = $courseApiResponse->getNextCursor();
            } while ($nextCursor !== null);

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    /**
     * @throws ApiException
     */
    public function getCourseById(string $identifier, array $options = []): CourseAndExtraData
    {
        return self::createCourseAndExtraDataFromCourseResource(
            $this->courseApi->getCourseByIdentifier($identifier), $options);
    }

    /**
     * @throws ApiException
     */
    public function getCourses(int $currentPageNumber, int $maxNumItemsPerPage, array $options = []): iterable
    {
        if ($filter = Options::getFilter($options)) {
            $pathMapping = [
                'identifier' => 'uid',
                'code' => 'courseCode',
                'name' => 'title',
            ];
            FilterTools::mapConditionPaths($filter, $pathMapping);
        }

        /** @var CachedCourse $cachedCourse */
        foreach (QueryHelper::getEntitiesPageNumberBased(CachedCourse::class, $this->entityManager,
            $currentPageNumber, $maxNumItemsPerPage) as $cachedCourse) {
            yield self::createCourseAndExtraDataFromCachedCourse($cachedCourse, $options);
        }
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
            // TODO
        ]);
    }
}
