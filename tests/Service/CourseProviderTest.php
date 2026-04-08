<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Tests\Service;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dbp\CampusonlineApi\PublicRestApi\Appointments\AppointmentResource;
use Dbp\Relay\BaseCourseBundle\Entity\Course;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\DependencyInjection\Configuration;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\DependencyInjection\DbpRelayBaseCourseConnectorCampusonlineExtension;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Entity\CachedCourse;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Entity\CachedCourseTitle;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\EventSubscriber\CourseEventSubscriber;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service\CourseProvider;
use Dbp\Relay\CoreBundle\Rest\Options;
use Dbp\Relay\CoreBundle\TestUtils\TestEntityManager;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

class CourseProviderTest extends ApiTestCase
{
    private const COURSE_TYPE_LOCAL_DATA_ATTRIBUTE_NAME = 'type';
    private const COURSE_TYPE_SOURCE_ATTRIBUTE_NAME = 'courseTypeKey';
    private const SEMESTER_LOCAL_DATA_ATTRIBUTE_NAME = 'term';
    private const SEMESTER_SOURCE_ATTRIBUTE_NAME = 'semesterKey';
    private const COURSE_IDENTITY_CODE_UID_LOCAL_DATA_ATTRIBUTE_NAME = 'course_identity_code';
    private const COURSE_IDENTITY_CODE_UID_SOURCE_ATTRIBUTE_NAME = 'courseIdentityCodeUid';
    private const LECTURERS_LOCAL_DATA_ATTRIBUTE_NAME = 'lecturers';

    private ?CourseProvider $courseProvider = null;
    private ?EntityManagerInterface $entityManager = null;
    private ?CourseEventSubscriber $courseEventSubscriber = null;

    protected function setUp(): void
    {
        parent::setUp();

        $container = self::bootKernel()->getContainer();

        $eventDispatcher = new EventDispatcher();
        $this->entityManager = TestEntityManager::setUpEntityManager($container,
            DbpRelayBaseCourseConnectorCampusonlineExtension::ENTITY_MANAGER_ID);

        $this->createStagingTables();

        $this->courseProvider = new CourseProvider($this->entityManager, $eventDispatcher);
        $this->courseProvider->setLogger(new NullLogger());
        $this->courseProvider->setConfig($this->createTestConfig());

        $this->courseEventSubscriber = new CourseEventSubscriber($this->courseProvider);
        $this->courseEventSubscriber->setConfig(self::createTestConfig());
        $eventDispatcher->addSubscriber($this->courseEventSubscriber);

        $this->recreateCourseCache();
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
        $options = [];
        Options::setLanguage($options, 'en');
        $course = $this->courseProvider->getCourseById('2', $options);
        $this->assertSame('2', $course->getIdentifier());
        $this->assertSame('Computational Science', $course->getName());
    }

    public function testGetCourseByIdentifierDeWithLocalData(): void
    {
        $options = [];
        Options::setLanguage($options, 'de');
        Options::requestLocalDataAttributes($options, [
            self::COURSE_TYPE_LOCAL_DATA_ATTRIBUTE_NAME,
            self::SEMESTER_LOCAL_DATA_ATTRIBUTE_NAME,
            self::COURSE_IDENTITY_CODE_UID_LOCAL_DATA_ATTRIBUTE_NAME,
        ]);
        $course = $this->courseProvider->getCourseById('2', $options);
        $this->assertSame('2', $course->getIdentifier());
        $this->assertSame('Komputationswissenschaft', $course->getName());
        $this->assertSame('UE', $course->getLocalDataValue(self::COURSE_TYPE_LOCAL_DATA_ATTRIBUTE_NAME));
        $this->assertSame(CourseProvider::getMostRecentSemesterKeys(1)[0],
            $course->getLocalDataValue(self::SEMESTER_LOCAL_DATA_ATTRIBUTE_NAME));
        $this->assertSame('1235',
            $course->getLocalDataValue(self::COURSE_IDENTITY_CODE_UID_LOCAL_DATA_ATTRIBUTE_NAME));
    }

    public function testGetCourseByIdWithLecturersLocalDataAttribute(): void
    {
        $courseUid = '3';
        $coResponses = [
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'],
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
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'],
                json_encode([
                    'items' => [
                        [
                            'key' => 'PRIM_LEC',
                            'name' => [
                                'value' => [
                                    'de' => 'Leiter*in',
                                    'en' => 'Main lecturer',
                                ],
                            ],
                        ],
                        [
                            'key' => 'SEC_LEC',
                            'name' => [
                                'value' => [
                                    'de' => 'Mitarbeiter*in',
                                    'en' => 'Co-lecturer',
                                ],
                            ],
                        ],
                    ],
                ])),
        ];

        $this->mockResponses($coResponses);

        $options = [];
        Options::requestLocalDataAttributes($options, [self::LECTURERS_LOCAL_DATA_ATTRIBUTE_NAME]);
        Options::setLanguage($options, 'de');
        $course = $this->courseProvider->getCourseById($courseUid, $options);

        $this->assertSame($courseUid, $course->getIdentifier());
        $this->assertSame('Komputationshalbwissenschaft', $course->getName());
        $this->assertSame('45_A', $course->getCode());
        $lecturers = $course->getLocalDataValue(self::LECTURERS_LOCAL_DATA_ATTRIBUTE_NAME);
        $this->assertCount(2, $lecturers);
        $lecturer = $lecturers[0];
        $this->assertSame('DEADBEEF2', $lecturer['personIdentifier']);
        $this->assertSame('PRIM_LEC', $lecturer['functionKey']);
        $this->assertSame('Leiter*in', $lecturer['functionName']);
        $lecturer = $lecturers[1];
        $this->assertSame('DEADBEEF3', $lecturer['personIdentifier']);
        $this->assertSame('SEC_LEC', $lecturer['functionKey']);
        $this->assertSame('Mitarbeiter*in', $lecturer['functionName']);
    }

    public function testGetCoursesDe(): void
    {
        $options = [];
        Options::setLanguage($options, 'de');
        $courses = $this->courseProvider->getCourses(1, 3, $options);
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

    public function testGetCoursesWithSearchParameter(): void
    {
        $options = [
            Course::SEARCH_PARAMETER_NAME => 'halb',
        ];
        $courses = $this->courseProvider->getCourses(1, 3, $options);
        $this->assertCount(1, $courses);
        $course = $courses[0];
        $this->assertSame('3', $course->getIdentifier());
        $this->assertSame('Komputationshalbwissenschaft', $course->getName());
        $this->assertSame('45_A', $course->getCode());

        $options = [
            Course::SEARCH_PARAMETER_NAME => '44',
        ];
        $courses = $this->courseProvider->getCourses(1, 3, $options);
        $this->assertCount(2, $courses);
        $course = $courses[0];
        $this->assertSame('1', $course->getIdentifier());
        $this->assertSame('Komputationsbesserwissenschaft', $course->getName());
        $this->assertSame('44_A', $course->getCode());
        $course = $courses[1];
        $this->assertSame('2', $course->getIdentifier());
        $this->assertSame('Komputationswissenschaft', $course->getName());
        $this->assertSame('44_B', $course->getCode());
    }

    public function testGetCoursesWithMulittermSearchParameter(): void
    {
        $options = [
            Course::SEARCH_PARAMETER_NAME => 'kompu 45',
        ];
        $courses = $this->courseProvider->getCourses(1, 3, $options);
        $this->assertCount(1, $courses);
        $course = $courses[0];
        $this->assertSame('3', $course->getIdentifier());
        $this->assertSame('Komputationshalbwissenschaft', $course->getName());
        $this->assertSame('45_A', $course->getCode());

        $options = [
            Course::SEARCH_PARAMETER_NAME => '45 besser',
        ];
        $courses = $this->courseProvider->getCourses(1, 3, $options);
        $this->assertCount(0, $courses);
    }

    public function testGetCoursesEnWithLocalData(): void
    {
        $options = [];
        Options::requestLocalDataAttributes($options, [
            self::COURSE_TYPE_LOCAL_DATA_ATTRIBUTE_NAME,
            self::SEMESTER_LOCAL_DATA_ATTRIBUTE_NAME,
            self::COURSE_IDENTITY_CODE_UID_LOCAL_DATA_ATTRIBUTE_NAME,
        ]);
        Options::setLanguage($options, 'en');
        $courses = $this->courseProvider->getCourses(1, 30, $options);
        $this->assertCount(3, $courses);
        $course = $courses[0];
        $this->assertSame('1', $course->getIdentifier());
        $this->assertSame('Computational Intelligence', $course->getName());
        $this->assertSame('44_A', $course->getCode());
        $this->assertSame('VO', $course->getLocalDataValue(self::COURSE_TYPE_LOCAL_DATA_ATTRIBUTE_NAME));
        $this->assertSame(CourseProvider::getMostRecentSemesterKeys(2)[0],
            $course->getLocalDataValue(self::SEMESTER_LOCAL_DATA_ATTRIBUTE_NAME));
        $this->assertSame('1234',
            $course->getLocalDataValue(self::COURSE_IDENTITY_CODE_UID_LOCAL_DATA_ATTRIBUTE_NAME));
        $course = $courses[1];
        $this->assertSame('2', $course->getIdentifier());
        $this->assertSame('Computational Science', $course->getName());
        $this->assertSame('44_B', $course->getCode());
        $this->assertSame('UE', $course->getLocalDataValue(self::COURSE_TYPE_LOCAL_DATA_ATTRIBUTE_NAME));
        $this->assertSame(CourseProvider::getMostRecentSemesterKeys(2)[0],
            $course->getLocalDataValue(self::SEMESTER_LOCAL_DATA_ATTRIBUTE_NAME));
        $this->assertSame('1235',
            $course->getLocalDataValue(self::COURSE_IDENTITY_CODE_UID_LOCAL_DATA_ATTRIBUTE_NAME));
        $course = $courses[2];
        $this->assertSame('3', $course->getIdentifier());
        $this->assertSame('Computational Unintelligence', $course->getName());
        $this->assertSame('45_A', $course->getCode());
        $this->assertSame('SEM', $course->getLocalDataValue(self::COURSE_TYPE_LOCAL_DATA_ATTRIBUTE_NAME));
        $this->assertSame(CourseProvider::getMostRecentSemesterKeys(2)[1],
            $course->getLocalDataValue(self::SEMESTER_LOCAL_DATA_ATTRIBUTE_NAME));
        $this->assertSame('1236',
            $course->getLocalDataValue(self::COURSE_IDENTITY_CODE_UID_LOCAL_DATA_ATTRIBUTE_NAME));
    }

    public function testGetCoursesEnWithLocalDataLecturers(): void
    {
        $coResponses = [
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'],
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
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'],
                json_encode([
                    'items' => [
                        [
                            'key' => 'PRIM_LEC',
                            'name' => [
                                'value' => [
                                    'de' => 'Leiter*in',
                                    'en' => 'Main lecturer',
                                ],
                            ],
                        ],
                        [
                            'key' => 'SEC_LEC',
                            'name' => [
                                'value' => [
                                    'de' => 'Mitarbeiter*in',
                                    'en' => 'Co-lecturer',
                                ],
                            ],
                        ],
                    ],
                ])),
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'],
                json_encode([
                    'items' => [], // no lecturers for course 2
                ])),
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'],
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
            self::LECTURERS_LOCAL_DATA_ATTRIBUTE_NAME,
        ]);
        Options::setLanguage($options, 'en');
        $courses = $this->courseProvider->getCourses(1, 30, $options);
        $this->assertCount(3, $courses);
        $course = $courses[0];
        $this->assertSame('1', $course->getIdentifier());
        $lecturers = $course->getLocalDataValue(self::LECTURERS_LOCAL_DATA_ATTRIBUTE_NAME);
        $lecturer = $lecturers[0];
        $this->assertSame('DEADBEEF1', $lecturer['personIdentifier']);
        $this->assertSame('PRIM_LEC', $lecturer['functionKey']);
        $this->assertSame('Main lecturer', $lecturer['functionName']);
        $course = $courses[1];
        $this->assertSame('2', $course->getIdentifier());
        $this->assertSame([], $course->getLocalDataValue(self::LECTURERS_LOCAL_DATA_ATTRIBUTE_NAME));
        $course = $courses[2];
        $this->assertSame('3', $course->getIdentifier());
        $lecturers = $course->getLocalDataValue(self::LECTURERS_LOCAL_DATA_ATTRIBUTE_NAME);
        $this->assertCount(2, $lecturers);
        $lecturer = $lecturers[0];
        $this->assertSame('DEADBEEF2', $lecturer['personIdentifier']);
        $this->assertSame('PRIM_LEC', $lecturer['functionKey']);
        $this->assertSame('Main lecturer', $lecturer['functionName']);
        $lecturer = $lecturers[1];
        $this->assertSame('DEADBEEF3', $lecturer['personIdentifier']);
        $this->assertSame('SEC_LEC', $lecturer['functionKey']);
        $this->assertSame('Co-lecturer', $lecturer['functionName']);
    }

    public function testGetSemesterKeys(): void
    {
        $this->assertEquals(['2025W', '2025S', '2024W', '2024S'], CourseProvider::getMostRecentSemesterKeys(4, new \DateTimeImmutable('2025-09-30')));
        $this->assertEquals(['2026S', '2025W', '2025S', '2024W'], CourseProvider::getMostRecentSemesterKeys(4, new \DateTimeImmutable('2025-10-01')));
        $this->assertEquals(['2026S', '2025W', '2025S', '2024W'], CourseProvider::getMostRecentSemesterKeys(4, new \DateTimeImmutable('2026-02-28')));
        $this->assertEquals(['2026W', '2026S', '2025W', '2025S'], CourseProvider::getMostRecentSemesterKeys(4, new \DateTimeImmutable('2026-03-01')));
    }

    public function testCreateCourseEventFromAppointmentResource(): void
    {
        $appointmentResource = new AppointmentResource([
            'uid' => 'event-1',
            'courseUid' => 'course-1',
            'startAt' => '2026-01-15T10:15:00',
            'endAt' => '2026-01-15T11:45:00',
            'eventTypeKey' => AppointmentResource::REGULAR_CLASS_EVENT_TYPE_KEY,
        ]);

        $courseEvent = $this->courseProvider->createCourseEventFromAppointmentResource($appointmentResource);

        $this->assertSame('event-1', $courseEvent->getIdentifier());
        $this->assertSame('course-1', $courseEvent->getCourseIdentifier());
        $this->assertEquals(new \DateTimeImmutable('2026-01-15T10:15:00', new \DateTimeZone('Europe/Vienna')),
            $courseEvent->getStartAt());
        $this->assertEquals(new \DateTimeImmutable('2026-01-15T11:45:00', new \DateTimeZone('Europe/Vienna')),
            $courseEvent->getEndAt());
        $this->assertSame('CLASS', $courseEvent->getTypeKey());
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
        $semesterKeys = CourseProvider::getMostRecentSemesterKeys(2);
        $coResponses = [
            // first semester responses:
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'],
                json_encode([
                    'items' => [
                        [
                            'uid' => '1',
                            'courseCode' => '44_A',
                            'courseTypeKey' => 'VO',
                            'courseIdentityCodeUid' => '1234',
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
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'],
                json_encode([
                    'items' => [
                        [
                            'uid' => '2',
                            'courseCode' => '44_B',
                            'courseTypeKey' => 'UE',
                            'courseIdentityCodeUid' => '1235',
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
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'],
                json_encode([
                    'items' => [
                        [
                            'uid' => '3',
                            'courseCode' => '45_A',
                            'courseTypeKey' => 'SEM',
                            'courseIdentityCodeUid' => '1236',
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
            // this is expected to fail, since sqlite does not support some operations
            $this->courseProvider->recreateCoursesCache();
        } catch (\Throwable) {
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

    private static function createTestConfig(): array
    {
        $config = [
            Configuration::DATABASE_URL => 'sqlite:///:memory:',
            Configuration::NUM_SEMESTERS_TO_PROVIDE => 2,
            Configuration::CAMPUS_ONLINE_NODE => [
                Configuration::BASE_URL_NODE => 'https://campusonline.net',
                Configuration::CLIENT_ID_NODE => 'client-id',
                Configuration::CLIENT_SECRET_NODE => 'client-secret',
                Configuration::EVENT_TIME_ZONE_NODE => 'Europe/Vienna',
            ],
        ];

        $config['local_data_mapping'] = [
            [
                'local_data_attribute' => self::COURSE_TYPE_LOCAL_DATA_ATTRIBUTE_NAME,
                'source_attribute' => self::COURSE_TYPE_SOURCE_ATTRIBUTE_NAME,
                'default_value' => '',
            ],
            [
                'local_data_attribute' => self::SEMESTER_LOCAL_DATA_ATTRIBUTE_NAME,
                'source_attribute' => self::SEMESTER_SOURCE_ATTRIBUTE_NAME,
                'default_value' => '',
            ],
            [
                'local_data_attribute' => self::COURSE_IDENTITY_CODE_UID_LOCAL_DATA_ATTRIBUTE_NAME,
                'source_attribute' => self::COURSE_IDENTITY_CODE_UID_SOURCE_ATTRIBUTE_NAME,
                'default_value' => '',
            ],
            [
                'local_data_attribute' => self::LECTURERS_LOCAL_DATA_ATTRIBUTE_NAME,
                'source_attribute' => CourseEventSubscriber::LECTURERS_SOURCE_DATA_ATTRIBUTE,
                'is_array' => true,
            ],
        ];

        return $config;
    }
}
