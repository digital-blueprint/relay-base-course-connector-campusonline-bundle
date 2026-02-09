<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Tests\Service;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
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

    private static function createEventSubscriberConfig(): array
    {
        $config = [];
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

        $this->courseEventSubscriber = new CourseEventSubscriber($this->courseProvider);
        $eventDispatcher->addSubscriber($this->courseEventSubscriber);

        $this->setUpPublicRestApi();
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
        $this->assertSame(CourseProvider::getSemesterKeys()[0],
            $course->getLocalDataValue(self::SEMESTER_LOCAL_DATA_ATTRIBUTE_NAME));
        $this->assertSame('1235',
            $course->getLocalDataValue(self::COURSE_IDENTITY_CODE_UID_LOCAL_DATA_ATTRIBUTE_NAME));
    }

    public function testGetCourseByIdWithLecturersLocalDataAttribute(): void
    {
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
            new Response(200, ['Content-Type' => 'applicateion/json;charset=utf-8'],
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
        $this->assertSame(CourseProvider::getSemesterKeys()[0],
            $course->getLocalDataValue(self::SEMESTER_LOCAL_DATA_ATTRIBUTE_NAME));
        $this->assertSame('1234',
            $course->getLocalDataValue(self::COURSE_IDENTITY_CODE_UID_LOCAL_DATA_ATTRIBUTE_NAME));
        $course = $courses[1];
        $this->assertSame('2', $course->getIdentifier());
        $this->assertSame('Computational Science', $course->getName());
        $this->assertSame('44_B', $course->getCode());
        $this->assertSame('UE', $course->getLocalDataValue(self::COURSE_TYPE_LOCAL_DATA_ATTRIBUTE_NAME));
        $this->assertSame(CourseProvider::getSemesterKeys()[0],
            $course->getLocalDataValue(self::SEMESTER_LOCAL_DATA_ATTRIBUTE_NAME));
        $this->assertSame('1235',
            $course->getLocalDataValue(self::COURSE_IDENTITY_CODE_UID_LOCAL_DATA_ATTRIBUTE_NAME));
        $course = $courses[2];
        $this->assertSame('3', $course->getIdentifier());
        $this->assertSame('Computational Unintelligence', $course->getName());
        $this->assertSame('45_A', $course->getCode());
        $this->assertSame('SEM', $course->getLocalDataValue(self::COURSE_TYPE_LOCAL_DATA_ATTRIBUTE_NAME));
        $this->assertSame(CourseProvider::getSemesterKeys()[1],
            $course->getLocalDataValue(self::SEMESTER_LOCAL_DATA_ATTRIBUTE_NAME));
        $this->assertSame('1236',
            $course->getLocalDataValue(self::COURSE_IDENTITY_CODE_UID_LOCAL_DATA_ATTRIBUTE_NAME));
    }

    public function testGetCoursesEnWithLocalDataLecturers(): void
    {
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
        $this->assertEquals([/* '2024S', */ '2024W', '2025S', '2025W'], CourseProvider::getSemesterKeys(new \DateTimeImmutable('2025-09-30')));
        $this->assertEquals([/* '2024W', */ '2025S', '2025W', '2026S'], CourseProvider::getSemesterKeys(new \DateTimeImmutable('2025-10-01')));
        $this->assertEquals([/* '2024W', */ '2025S', '2025W', '2026S'], CourseProvider::getSemesterKeys(new \DateTimeImmutable('2026-02-28')));
        $this->assertEquals([/* '2025S', */ '2025W', '2026S', '2026W'], CourseProvider::getSemesterKeys(new \DateTimeImmutable('2026-03-01')));
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
        $semesterKeys = CourseProvider::getSemesterKeys();
        $coResponses = [
            // first semester responses:
            new Response(200, ['Content-Type' => 'applicateion/json;charset=utf-8'],
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
            new Response(200, ['Content-Type' => 'applicateion/json;charset=utf-8'],
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
            new Response(200, ['Content-Type' => 'applicateion/json;charset=utf-8'],
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

    private function setUpPublicRestApi(): void
    {
        $this->courseProvider->setConfig($this->getPublicRestApiConfig());
        $this->courseEventSubscriber->setConfig(self::createEventSubscriberConfig());

        $this->recreateCourseCache();
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
