<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Tests\Service;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Apis\PublicRestCourseApi;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\DependencyInjection\Configuration;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\DependencyInjection\DbpRelayBaseCourseConnectorCampusonlineExtension;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Entity\CachedCourse;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Entity\CachedCourseTitle;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\EventSubscriber\CourseEventSubscriber;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service\CourseProvider;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Rest\Options;
use Dbp\Relay\CoreBundle\TestUtils\TestEntityManager;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;

class CourseProviderTest extends ApiTestCase
{
    private const COURSE_TYPE_LOCAL_DATA_ATTRIBUTE_NAME = 'type';
    private const COURSE_TYPE_LEGACY_SOURCE_ATTRIBUTE_NAME = 'type';
    private const COURSE_TYPE_SOURCE_ATTRIBUTE_NAME = 'courseTypeKey';
    private const SEMESTER_LOCAL_DATA_ATTRIBUTE_NAME = 'term';
    private const SEMESTER_LEGACY_SOURCE_ATTRIBUTE_NAME = 'teachingTerm';
    private const SEMESTER_SOURCE_ATTRIBUTE_NAME = 'semesterKey';

    private ?CourseProvider $courseProvider = null;
    private ?EventDispatcher $eventDispatcher = null;
    private ?EntityManagerInterface $entityManager = null;
    private ?CourseEventSubscriber $courseEventSubscriber = null;

    private static function createEventSubscriberConfig(bool $publicRest): array
    {
        $config = [];
        $config['local_data_mapping'] = [
            [
                'local_data_attribute' => self::COURSE_TYPE_LOCAL_DATA_ATTRIBUTE_NAME,
                'source_attribute' => $publicRest ?
                    self::COURSE_TYPE_SOURCE_ATTRIBUTE_NAME : self::COURSE_TYPE_LEGACY_SOURCE_ATTRIBUTE_NAME,
                'default_value' => '',
            ],
            [
                'local_data_attribute' => self::SEMESTER_LOCAL_DATA_ATTRIBUTE_NAME,
                'source_attribute' => $publicRest ?
                    self::SEMESTER_SOURCE_ATTRIBUTE_NAME : self::SEMESTER_LEGACY_SOURCE_ATTRIBUTE_NAME,
                'default_value' => '',
            ],
        ];

        return $config;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $container = self::bootKernel()->getContainer();

        $this->eventDispatcher = new EventDispatcher();
        $this->entityManager = TestEntityManager::setUpEntityManager($container,
            DbpRelayBaseCourseConnectorCampusonlineExtension::ENTITY_MANAGER_ID);

        $this->createStagingTables();

        $this->courseProvider = new CourseProvider($this->entityManager, $this->eventDispatcher);
        // $this->courseProvider->setConfig($this->getPublicRestApiConfig());
        $this->courseProvider->setCache(new ArrayAdapter(), 3600);
        $this->courseProvider->setLogger(new NullLogger());

        $this->courseEventSubscriber = new CourseEventSubscriber($this->courseProvider);
        // $this->courseEventSubscriber->setConfig(self::createEventSubscriberConfig(true));
        $this->eventDispatcher->addSubscriber($this->courseEventSubscriber);
    }

    private function mockResponses(array $responses, bool $mockAuthServerResponses = false): void
    {
        if ($mockAuthServerResponses) {
            $responses = array_merge(self::createMockAuthServerResponses(), $responses);
        }

        $stack = HandlerStack::create(new MockHandler($responses));
        $this->courseProvider->setClientHandler($stack);
    }

    public function testGetCourseByIdentifierEn(): void
    {
        $this->setUpPublicRestApi();

        $options = [];
        Options::setLanguage($options, 'en');
        $course = $this->courseProvider->getCourseById('2', $options);
        $this->assertSame('2', $course->getIdentifier());
        $this->assertSame('Computational Science', $course->getName());
    }

    public function testGetCourseByIdentifierDeWithLocalData(): void
    {
        $this->setUpPublicRestApi();

        $options = [];
        Options::setLanguage($options, 'de');
        Options::requestLocalDataAttributes($options, [
            self::COURSE_TYPE_LOCAL_DATA_ATTRIBUTE_NAME,
            self::SEMESTER_LOCAL_DATA_ATTRIBUTE_NAME,
        ]);
        $course = $this->courseProvider->getCourseById('2', $options);
        $this->assertSame('2', $course->getIdentifier());
        $this->assertSame('Komputationswissenschaft', $course->getName());
        $this->assertSame('UE', $course->getLocalDataValue(self::COURSE_TYPE_LOCAL_DATA_ATTRIBUTE_NAME));
        $this->assertSame(PublicRestCourseApi::getSemesterKeys()[0],
            $course->getLocalDataValue(self::SEMESTER_LOCAL_DATA_ATTRIBUTE_NAME));
    }

    public function testGetCourseByIdWithLecturersLocalDataAttribute(): void
    {
        $this->setUpPublicRestApi();

        $courseUid = '3';
        $coResponses = [
            new Response(200, ['Content-Type' => 'applicateion/json;charset=utf-8'],
                json_encode([
                    'items' => [
                        [
                            'uid' => '42',
                            'functionKey' => 'PRIM_LEC',
                            'personUid' => 'DEADBEEF2',
                            'courseUid' => $courseUid,
                        ],
                        [
                            'uid' => '43',
                            'functionKey' => 'SEC_LEC',
                            'personUid' => 'DEADBEEF3',
                            'courseUid' => $courseUid,
                        ],
                    ],
                ])),
        ];

        $this->mockResponses($coResponses);

        $options = [];
        Options::requestLocalDataAttributes($options, [CourseEventSubscriber::LECTURERS_LOCAL_DATA_ATTRIBUTE]);
        Options::setLanguage($options, 'de');
        $course = $this->courseProvider->getCourseById($courseUid, $options);

        $this->assertSame($courseUid, $course->getIdentifier());
        $this->assertSame('Komputationshalbwissenschaft', $course->getName());
        $this->assertSame('45_A', $course->getCode());
        $this->assertSame(['DEADBEEF2', 'DEADBEEF3'],
            $course->getLocalDataValue(CourseEventSubscriber::LECTURERS_LOCAL_DATA_ATTRIBUTE));
    }

    public function testGetCoursesDe(): void
    {
        $this->setUpPublicRestApi();

        $options = [];
        Options::setLanguage($options, 'de');
        $courses = $this->courseProvider->getCourses(1, 30, $options);
        $this->assertCount(3, $courses);
        $course = $courses[0];
        $this->assertSame('1', $course->getIdentifier());
        $this->assertSame('Komputationsbesserwissenschaft', $course->getName());
        $this->assertSame('44_A', $course->getCode());
        $course = $courses[1];
        $this->assertSame('2', $course->getIdentifier());
        $this->assertSame('Komputationswissenschaft', $course->getName());
        $this->assertSame('44_B', $course->getCode());
        $course = $courses[2];
        $this->assertSame('3', $course->getIdentifier());
        $this->assertSame('Komputationshalbwissenschaft', $course->getName());
        $this->assertSame('45_A', $course->getCode());
    }

    public function testGetCoursesEnWithLocalData(): void
    {
        $this->setUpPublicRestApi();

        $options = [];
        Options::requestLocalDataAttributes($options, [
            self::COURSE_TYPE_LOCAL_DATA_ATTRIBUTE_NAME,
            self::SEMESTER_LOCAL_DATA_ATTRIBUTE_NAME,
        ]);
        Options::setLanguage($options, 'en');
        $courses = $this->courseProvider->getCourses(1, 30, $options);
        $this->assertCount(3, $courses);
        $course = $courses[0];
        $this->assertSame('1', $course->getIdentifier());
        $this->assertSame('Computational Intelligence', $course->getName());
        $this->assertSame('44_A', $course->getCode());
        $this->assertSame('VO', $course->getLocalDataValue(self::COURSE_TYPE_LOCAL_DATA_ATTRIBUTE_NAME));
        $this->assertSame(PublicRestCourseApi::getSemesterKeys()[0],
            $course->getLocalDataValue(self::SEMESTER_LOCAL_DATA_ATTRIBUTE_NAME));
        $course = $courses[1];
        $this->assertSame('2', $course->getIdentifier());
        $this->assertSame('Computational Science', $course->getName());
        $this->assertSame('44_B', $course->getCode());
        $this->assertSame('UE', $course->getLocalDataValue(self::COURSE_TYPE_LOCAL_DATA_ATTRIBUTE_NAME));
        $this->assertSame(PublicRestCourseApi::getSemesterKeys()[0],
            $course->getLocalDataValue(self::SEMESTER_LOCAL_DATA_ATTRIBUTE_NAME));
        $course = $courses[2];
        $this->assertSame('3', $course->getIdentifier());
        $this->assertSame('Computational Unintelligence', $course->getName());
        $this->assertSame('45_A', $course->getCode());
        $this->assertSame('SEM', $course->getLocalDataValue(self::COURSE_TYPE_LOCAL_DATA_ATTRIBUTE_NAME));
        $this->assertSame(PublicRestCourseApi::getSemesterKeys()[1],
            $course->getLocalDataValue(self::SEMESTER_LOCAL_DATA_ATTRIBUTE_NAME));
    }

    public function testGetCoursesEnWithLocalDataLecturers(): void
    {
        $this->setUpPublicRestApi();

        $coResponses = [
            new Response(200, ['Content-Type' => 'applicateion/json;charset=utf-8'],
                json_encode([
                    'items' => [
                        [
                            'uid' => '41',
                            'functionKey' => 'PRIM_LEC',
                            'personUid' => 'DEADBEEF1',
                            'courseUid' => '1',
                        ],
                    ],
                ])),
            new Response(200, ['Content-Type' => 'applicateion/json;charset=utf-8'],
                json_encode([
                    'items' => [], // no lecturers for course 2
                ])),
            new Response(200, ['Content-Type' => 'applicateion/json;charset=utf-8'],
                json_encode([
                    'items' => [
                        [
                            'uid' => '42',
                            'functionKey' => 'PRIM_LEC',
                            'personUid' => 'DEADBEEF2',
                            'courseUid' => '3',
                        ],
                        [
                            'uid' => '43',
                            'functionKey' => 'SEC_LEC',
                            'personUid' => 'DEADBEEF3',
                            'courseUid' => '3',
                        ],
                    ],
                ])),
        ];

        $this->mockResponses($coResponses);

        $options = [];
        Options::requestLocalDataAttributes($options, [
            CourseEventSubscriber::LECTURERS_LOCAL_DATA_ATTRIBUTE,
        ]);
        Options::setLanguage($options, 'en');
        $courses = $this->courseProvider->getCourses(1, 30, $options);
        $this->assertCount(3, $courses);
        $course = $courses[0];
        $this->assertSame('1', $course->getIdentifier());
        $this->assertSame(['DEADBEEF1'], $course->getLocalDataValue(CourseEventSubscriber::LECTURERS_LOCAL_DATA_ATTRIBUTE));
        $course = $courses[1];
        $this->assertSame('2', $course->getIdentifier());
        $this->assertSame([], $course->getLocalDataValue(CourseEventSubscriber::LECTURERS_LOCAL_DATA_ATTRIBUTE));
        $course = $courses[2];
        $this->assertSame('3', $course->getIdentifier());
        $this->assertSame(['DEADBEEF2', 'DEADBEEF3'],
            $course->getLocalDataValue(CourseEventSubscriber::LECTURERS_LOCAL_DATA_ATTRIBUTE));
    }

    public function testGetCoursesLegacy(): void
    {
        $this->setUpLegacyApi();

        $this->mockResponses([
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'],
                file_get_contents(__DIR__.'/courses_by_organization_response.xml')),
        ]);

        $courses = $this->courseProvider->getCourses(1, 50);
        $this->assertCount(34, $courses);
        $course = $courses[0];
        $this->assertSame('241333', $course->getIdentifier());
        $this->assertSame('Technische Informatik 1', $course->getName());
    }

    public function testGetCoursesPaginationLegacy(): void
    {
        $this->setUpLegacyApi();

        $this->mockResponses([
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'],
                file_get_contents(__DIR__.'/courses_by_organization_response.xml')),
        ]);

        $courses = $this->courseProvider->getCourses(1, 15);
        $this->assertCount(15, $courses);

        $courseIdsPage1 = [];
        foreach ($courses as $course) {
            $courseIdsPage1[] = $course->getIdentifier();
        }

        $courses = $this->courseProvider->getCourses(2, 15);
        $this->assertCount(15, $courses);

        $courseIdsPage2 = [];
        foreach ($courses as $course) {
            $courseIdsPage2[] = $course->getIdentifier();
        }

        $courses = $this->courseProvider->getCourses(3, 15);
        $this->assertCount(4, $courses);

        $courseIdsPage3 = [];
        foreach ($courses as $course) {
            $courseIdsPage3[] = $course->getIdentifier();
        }

        $courseIds = array_unique(array_merge($courseIdsPage1, $courseIdsPage2, $courseIdsPage3));
        $this->assertCount(34, $courseIds);
    }

    public function testGetCourses500Legacy(): void
    {
        $this->setUpLegacyApi();

        $this->mockResponses([
            new Response(500, ['Content-Type' => 'text/xml;charset=utf-8'], ''),
        ]);

        $this->expectException(ApiError::class);
        $this->courseProvider->getCourses(1, 50);
    }

    public function testGetCoursesInvalidXMLLegacy(): void
    {
        $this->setUpLegacyApi();

        $this->mockResponses([
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'],
                file_get_contents(__DIR__.'/course_response_invalid_xml.xml')),
        ]);

        try {
            $this->courseProvider->getCourses(1, 50);
            $this->fail('Expected an ApiError to be thrown');
        } catch (ApiError $apiError) {
            $this->assertEquals(500, $apiError->getStatusCode());
        }
    }

    public function testGetCourseByIdLegacy(): void
    {
        $this->setUpLegacyApi();

        $this->mockResponses([
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'],
                file_get_contents(__DIR__.'/course_by_id_response.xml')),
        ]);

        $course = $this->courseProvider->getCourseById('240759');

        $this->assertSame('240759', $course->getIdentifier());
        $this->assertSame('Computational Intelligence', $course->getName());
        $this->assertSame('442071', $course->getCode());
    }

    public function testGetCourseByIdWithLecturersLocalDataAttributeLegacy(): void
    {
        $this->setUpLegacyApi();

        $this->mockResponses([
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'],
                file_get_contents(__DIR__.'/course_by_id_response.xml')),
        ]);

        $options = [];
        Options::requestLocalDataAttributes($options, [CourseEventSubscriber::LECTURERS_LOCAL_DATA_ATTRIBUTE]);
        $course = $this->courseProvider->getCourseById('240759', $options);

        $this->assertSame('240759', $course->getIdentifier());
        $this->assertSame('Computational Intelligence', $course->getName());
        $this->assertSame('442071', $course->getCode());
        $this->assertSame(['DEADBEEF2', 'DEADBEEF3', 'DEADBEEF4', 'DEADBEEF'],
            $course->getLocalDataValue(CourseEventSubscriber::LECTURERS_LOCAL_DATA_ATTRIBUTE));
    }

    public function testGetCourseByIdNotFoundLegacy(): void
    {
        $this->setUpLegacyApi();

        $this->mockResponses([
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'],
                file_get_contents(__DIR__.'/course_by_id_response.xml')),
        ]);

        try {
            $this->courseProvider->getCourseById('404');
            $this->fail('Expected an ApiError to be thrown');
        } catch (\Throwable $exception) {
            $this->assertInstanceOf(ApiError::class, $exception);
            $this->assertEquals(404, $exception->getStatusCode());
        }
    }

    public function testGetCourseById503Legacy(): void
    {
        $this->setUpLegacyApi();

        $this->mockResponses([
            new Response(503, ['Content-Type' => 'text/xml;charset=utf-8'], ''),
        ]);

        try {
            $this->courseProvider->getCourseById('240759');
            $this->fail('Expected an ApiError to be thrown');
        } catch (\Throwable $exception) {
            $this->assertInstanceOf(ApiError::class, $exception);
            $this->assertEquals(502, $exception->getStatusCode());
        }
    }

    public function testGetCoursesByOrganizationLegacy(): void
    {
        $this->setUpLegacyApi();

        $this->mockResponses([
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'],
                file_get_contents(__DIR__.'/courses_by_organization_response.xml')),
        ]);

        $courses = $this->courseProvider->getCourses(1, 50, ['organization' => '2337']);
        $this->assertCount(34, $courses);

        $course = $courses[0];
        $this->assertSame('241333', $course->getIdentifier());
        $this->assertSame('Technische Informatik 1', $course->getName());
        $this->assertSame('448001', $course->getCode());
    }

    public function testGetCourseByIdLocalDataLegacy(): void
    {
        $this->setUpLegacyApi();

        $this->mockResponses([
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'],
                file_get_contents(__DIR__.'/course_by_id_response.xml')),
        ]);

        $options = [];
        Options::requestLocalDataAttributes($options,
            [self::COURSE_TYPE_LOCAL_DATA_ATTRIBUTE_NAME, self::SEMESTER_LOCAL_DATA_ATTRIBUTE_NAME]);
        $course = $this->courseProvider->getCourseById('240759', $options);

        $this->assertSame('240759', $course->getIdentifier());
        $this->assertSame('Computational Intelligence', $course->getName());
        $this->assertSame('UE', $course->getLocalDataValue(self::COURSE_TYPE_LOCAL_DATA_ATTRIBUTE_NAME));
        $this->assertSame('Sommersemester 2021',
            $course->getLocalDataValue(self::SEMESTER_LOCAL_DATA_ATTRIBUTE_NAME));
    }

    public function testGetCoursesLocalDataLegacy(): void
    {
        $this->setUpLegacyApi();

        $this->mockResponses([
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'],
                file_get_contents(__DIR__.'/courses_by_organization_response.xml')),
        ]);

        $options = [];
        Options::requestLocalDataAttributes($options,
            [self::COURSE_TYPE_LOCAL_DATA_ATTRIBUTE_NAME, self::SEMESTER_LOCAL_DATA_ATTRIBUTE_NAME]);
        $courses = $this->courseProvider->getCourses(1, 50, $options);
        $this->assertCount(34, $courses);

        foreach ($courses as $course) {
            $this->assertNotNull($course->getLocalDataValue(self::COURSE_TYPE_LOCAL_DATA_ATTRIBUTE_NAME));
            $this->assertNotNull($course->getLocalDataValue(self::SEMESTER_LOCAL_DATA_ATTRIBUTE_NAME));
        }
    }

    public function testGetCoursesSearchParameterLegacy(): void
    {
        $this->setUpLegacyApi();

        $this->mockResponses([
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'],
                file_get_contents(__DIR__.'/courses_by_organization_response.xml')),
        ]);

        $courses = $this->courseProvider->getCourses(1, 50, ['search' => 'labor']);
        $this->assertCount(6, $courses);

        $courseIds = [];
        foreach ($courses as $course) {
            $courseIds[] = $course->getIdentifier();
        }

        $this->assertContains('234661', $courseIds);
        $this->assertContains('236259', $courseIds);
        $this->assertContains('236526', $courseIds);
        $this->assertContains('236527', $courseIds);
        $this->assertContains('237934', $courseIds);
        $this->assertContains('238140', $courseIds);

        $this->mockResponses([
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'],
                file_get_contents(__DIR__.'/courses_by_organization_response.xml')),
        ]);

        $courses = $this->courseProvider->getCourses(1, 50, ['search' => '44801']);
        $this->assertCount(4, $courses);

        $courseCodes = [];
        foreach ($courses as $course) {
            $courseCodes[] = $course->getCode();
        }

        $this->assertContains('448010', $courseCodes);
        $this->assertContains('448011', $courseCodes);
        $this->assertContains('448018', $courseCodes);
        $this->assertContains('448019', $courseCodes);

        $this->mockResponses([
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'],
                file_get_contents(__DIR__.'/courses_by_organization_response.xml')),
        ]);

        $courses = $this->courseProvider->getCourses(1, 50, ['search' => 'foobar']);
        $this->assertEquals([], $courses);
    }

    public function testGetCoursesSearchParameterPaginationLegacy(): void
    {
        $this->setUpLegacyApi();

        $this->mockResponses([
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'],
                file_get_contents(__DIR__.'/courses_by_organization_response.xml')),
        ]);

        $courses = $this->courseProvider->getCourses(1, 5, ['search' => 'labor']);
        $this->assertCount(5, $courses);

        $courseIdsPage1 = [];
        foreach ($courses as $course) {
            $courseIdsPage1[] = $course->getIdentifier();
        }

        $courses = $this->courseProvider->getCourses(2, 5, ['search' => 'labor']);
        $this->assertCount(1, $courses);

        $courseIdsPage2 = [];
        foreach ($courses as $course) {
            $courseIdsPage2[] = $course->getIdentifier();
        }

        $courseIds = array_unique(array_merge($courseIdsPage1, $courseIdsPage2));
        $this->assertCount(6, $courseIds);
    }

    public function testGetSemesterKeys(): void
    {
        $this->assertEquals(['2025S', '2025W'], PublicRestCourseApi::getSemesterKeys(new \DateTimeImmutable('2025-09-30')));
        $this->assertEquals(['2025W', '2026S'], PublicRestCourseApi::getSemesterKeys(new \DateTimeImmutable('2025-10-01')));
        $this->assertEquals(['2025W', '2026S'], PublicRestCourseApi::getSemesterKeys(new \DateTimeImmutable('2026-02-28')));
        $this->assertEquals(['2026S', '2026W'], PublicRestCourseApi::getSemesterKeys(new \DateTimeImmutable('2026-03-01')));
    }

    private static function createMockAuthServerResponses(): array
    {
        return [
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'], '{"authServerUrl": "https://auth-server.net/"}'),
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'], '{"token_endpoint": "https://token-endpoint.net/"}'),
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'], '{"access_token": "token", "expires_in": 3600, "token_type": "Bearer"}'),
        ];
    }

    private function recreateCourseCache(): void
    {
        $semesterKeys = PublicRestCourseApi::getSemesterKeys();
        $coResponses = [
            // first semester responses:
            new Response(200, ['Content-Type' => 'applicateion/json;charset=utf-8'],
                json_encode([
                    'items' => [
                        [
                            'uid' => '1',
                            'courseCode' => '44_A',
                            'courseTypeKey' => 'VO',
                            'semesterKey' => $semesterKeys[0],
                            'title' => [
                                'value' => [
                                    'en' => 'Computational Intelligence',
                                    'de' => 'Komputationsbesserwissenschaft',
                                ],
                            ],
                        ],
                    ],
                    'nextCursor' => 'cursor123',
                ])),
            new Response(200, ['Content-Type' => 'applicateion/json;charset=utf-8'],
                json_encode([
                    'items' => [
                        [
                            'uid' => '2',
                            'courseCode' => '44_B',
                            'courseTypeKey' => 'UE',
                            'semesterKey' => $semesterKeys[0],
                            'title' => [
                                'value' => [
                                    'en' => 'Computational Science',
                                    'de' => 'Komputationswissenschaft',
                                ],
                            ],
                        ],
                    ],
                ])),
            // second semester response:
            new Response(200, ['Content-Type' => 'applicateion/json;charset=utf-8'],
                json_encode([
                    'items' => [
                        [
                            'uid' => '3',
                            'courseCode' => '45_A',
                            'courseTypeKey' => 'SEM',
                            'semesterKey' => $semesterKeys[1],
                            'title' => [
                                'value' => [
                                    'en' => 'Computational Unintelligence',
                                    'de' => 'Komputationshalbwissenschaft',
                                ],
                            ],
                        ],
                    ],
                ])),
        ];
        $this->mockResponses($coResponses, true);
        try {
            $this->courseProvider->recreateCoursesCache();
        } catch (\Throwable $exception) { // this is expected to not fail, since sqlite does not support some operations
            $coursesLiveTable = CachedCourse::TABLE_NAME;
            $coursesStagingTable = CachedCourse::STAGING_TABLE_NAME;
            $coursesTempTable = 'courses_old';
            $courseTitlesLiveTable = CachedCourseTitle::TABLE_NAME;
            $courseTitlesStagingTable = CachedCourseTitle::STAGING_TABLE_NAME;
            $courseTitlesTempTable = 'course_titles_old';
            $connection = $this->entityManager->getConnection();
            $connection->executeStatement("ALTER TABLE $coursesLiveTable RENAME TO $coursesTempTable;");
            $connection->executeStatement("ALTER TABLE $coursesStagingTable RENAME TO $coursesLiveTable;");
            $connection->executeStatement("ALTER TABLE $coursesTempTable RENAME TO $coursesStagingTable;");
            $connection->executeStatement("ALTER TABLE $courseTitlesLiveTable RENAME TO $courseTitlesTempTable;");
            $connection->executeStatement("ALTER TABLE $courseTitlesStagingTable RENAME TO $courseTitlesLiveTable;");
            $connection->executeStatement("ALTER TABLE $courseTitlesTempTable RENAME TO $courseTitlesStagingTable;");
        }
    }

    private function createStagingTables(): void
    {
        $coursesTableName = CachedCourse::TABLE_NAME;
        $coursesStagingTableName = CachedCourse::STAGING_TABLE_NAME;

        $this->entityManager->getConnection()->executeStatement(
            "CREATE TABLE $coursesStagingTableName AS SELECT * FROM $coursesTableName");

        $courseTitlesTableName = CachedCourseTitle::TABLE_NAME;
        $courseTitlesStagingTableName = CachedCourseTitle::STAGING_TABLE_NAME;

        $this->entityManager->getConnection()->executeStatement(
            "CREATE TABLE $courseTitlesStagingTableName AS SELECT * FROM $courseTitlesTableName");
    }

    private function setUpLegacyApi(): void
    {
        $this->courseProvider->setConfig($this->getLegacyApiConfig());
        $this->courseProvider->reset();
        $this->courseEventSubscriber->setConfig(self::createEventSubscriberConfig(false));
    }

    private function setUpPublicRestApi(): void
    {
        $this->courseProvider->setConfig($this->getPublicRestApiConfig());
        $this->courseProvider->reset();
        $this->courseEventSubscriber->setConfig(self::createEventSubscriberConfig(true));

        $this->recreateCourseCache();
    }

    private function getLegacyApiConfig(): array
    {
        $config = [];
        $config[Configuration::CAMPUS_ONLINE_NODE] = [
            'legacy' => true,
            'org_root_id' => '1',
        ];

        return $config;
    }

    private function getPublicRestApiConfig(): array
    {
        $config = [];
        $config[Configuration::CAMPUS_ONLINE_NODE] = [
            'legacy' => false,
            'base_url' => 'https://campusonline.at/campusonline/ws/public/rest/',
            'client_id' => 'client',
            'client_secret' => 'secret',
        ];

        return $config;
    }
}
