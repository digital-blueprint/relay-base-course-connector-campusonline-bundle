<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Tests\Service;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dbp\CampusonlineApi\PublicRestApi\Appointments\AppointmentResource;
use Dbp\Relay\BaseCourseBundle\Entity\Course;
use Dbp\Relay\BaseCourseBundle\Entity\CourseEvent;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\DependencyInjection\Configuration;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\DependencyInjection\DbpRelayBaseCourseConnectorCampusonlineExtension;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Entity\CachedCourse;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Entity\CachedCourseTitle;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\EventSubscriber\CourseEventEventSubscriber;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\EventSubscriber\CourseEventSubscriber;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service\CourseProvider;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\LocalData\LocalData;
use Dbp\Relay\CoreBundle\Rest\Options;
use Dbp\Relay\CoreBundle\Rest\Query\Filter\FilterTreeBuilder;
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
    private const EXPECTED_PREVIOUS_KNOWLEDGE_LOCAL_DATA_ATTRIBUTE = 'expectedPreviousKnowledge';

    private const TEACHING_METHOD_DESCRIPTION_LOCAL_DATA_ATTRIBUTE = 'teachingMethodDescription';
    private const TEACHING_METHOD_KEY_LOCAL_DATA_ATTRIBUTE = 'teachingMethodKey';
    private const DESCRIPTION_LOCAL_DATA_ATTRIBUTE = 'description';
    private const OBJECTIVE_LOCAL_DATA_ATTRIBUTE = 'objective';

    private const SEMESTER_SOURCE_ATTRIBUTE_NAME = 'semesterKey';
    private const COURSE_IDENTITY_CODE_UID_LOCAL_DATA_ATTRIBUTE_NAME = 'course_identity_code';
    private const COURSE_IDENTITY_CODE_UID_SOURCE_ATTRIBUTE_NAME = 'courseIdentityCodeUid';
    private const LECTURERS_LOCAL_DATA_ATTRIBUTE_NAME = 'lecturers';
    private const ATTENDEES_LOCAL_DATA_ATTRIBUTE_NAME = 'attendees';
    private const WAITING_LIST_LOCAL_DATA_ATTRIBUTE_NAME = 'waitingList';
    private const COURSE_GROUPS_LOCAL_DATA_ATTRIBUTE_NAME = 'courseGroups';
    private const COURSE_GROUP_REGISTRATIONS_LOCAL_DATA_ATTRIBUTE_NAME = 'courseGroupRegistrations';

    /**
     * @var CourseEvent local data attributes:
     */
    private const COURSE_EVENT_COMMENT_LOCAL_DATA_ATTRIBUTE = 'comment';
    private const COURSE_EVENT_ROOM_UID_LOCAL_DATA_ATTRIBUTE = 'roomIdentifier';
    private const COURSE_EVENT_EVENT_TYPE_KEY_LOCAL_DATA_ATTRIBUTE = 'eventTypeKey';

    private ?CourseProvider $courseProvider = null;
    private ?EntityManagerInterface $entityManager = null;
    private ?MockHandler $mockHandler = null;

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

        $courseEventSubscriber = new CourseEventSubscriber($this->courseProvider);
        $courseEventSubscriber->setConfig(self::createTestConfig());
        $eventDispatcher->addSubscriber($courseEventSubscriber);

        $coursEventEventSubscriber = new CourseEventEventSubscriber($this->courseProvider);
        $coursEventEventSubscriber->setConfig(self::createTestConfig());
        $eventDispatcher->addSubscriber($coursEventEventSubscriber);

        $this->recreateCourseCache();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $this->assertEmpty($this->mockHandler ?? [], 'Not all expected API calls were made.');
    }

    private function mockResponses(array $responses, bool $mockAuthServerResponses = true): void
    {
        if ($mockAuthServerResponses) {
            $responses = array_merge(self::createMockAuthServerResponses(), $responses);
        }

        $this->mockHandler = new MockHandler($responses);
        $stack = HandlerStack::create($this->mockHandler);
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

    public function testGetCoursesWithMultitermSearchParameter(): void
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

    public function testGetCourseByIdWithAttributeFilter(): void
    {
        $filter = FilterTreeBuilder::create()
            ->iStartsWith('code', '44')
            ->createFilter();

        $options = [];
        Options::setFilter($options, $filter);
        $course = $this->courseProvider->getCourseById('2', $options);
        $this->assertSame('2', $course->getIdentifier());
    }

    public function testGetCourseByIdWithAttributeFilterNotFound1(): void
    {
        $filter = FilterTreeBuilder::create()
            ->iStartsWith('code', '44')
            ->createFilter();

        $options = [];
        Options::setFilter($options, $filter);
        try {
            $this->courseProvider->getCourseById('3', $options);
            $this->fail('Expected ApiError not thrown');
        } catch (ApiError $apiError) {
            $this->assertSame(404, $apiError->getStatusCode());
        }
    }

    public function testGetCourseByIdWithAttributeFilterNotFound2(): void
    {
        $filter = FilterTreeBuilder::create()
            ->iStartsWith('code', 'foo')
            ->createFilter();

        $options = [];
        Options::setFilter($options, $filter);
        try {
            $this->courseProvider->getCourseById('2', $options);
            $this->fail('Expected ApiError not thrown');
        } catch (ApiError $apiError) {
            $this->assertSame(404, $apiError->getStatusCode());
        }
    }

    public function testGetCourseByIdWithLocalDataAttributeFilter(): void
    {
        $filter = FilterTreeBuilder::create()
            ->equals(LocalData::BASE_PATH.self::COURSE_IDENTITY_CODE_UID_LOCAL_DATA_ATTRIBUTE_NAME, '1235')
            ->createFilter();

        $options = [];
        Options::setFilter($options, $filter);
        $course = $this->courseProvider->getCourseById('2', $options);
        $this->assertSame('2', $course->getIdentifier());
    }

    public function testGetCourseByIdWithLocalDataAttributeFilterNotFound1(): void
    {
        $filter = FilterTreeBuilder::create()
            ->equals(LocalData::BASE_PATH.self::COURSE_IDENTITY_CODE_UID_LOCAL_DATA_ATTRIBUTE_NAME, '1235')
            ->createFilter();

        $options = [];
        Options::setFilter($options, $filter);
        try {
            $this->courseProvider->getCourseById('1', $options);
            $this->fail('Expected ApiError not thrown');
        } catch (ApiError $apiError) {
            $this->assertSame(404, $apiError->getStatusCode());
        }
    }

    public function testGetCourseByIdWithLocalDataAttributeFilterNotFound2(): void
    {
        $filter = FilterTreeBuilder::create()
            ->equals(LocalData::BASE_PATH.self::COURSE_IDENTITY_CODE_UID_LOCAL_DATA_ATTRIBUTE_NAME, 'foo')
            ->createFilter();

        $options = [];
        Options::setFilter($options, $filter);
        try {
            $this->courseProvider->getCourseById('2', $options);
            $this->fail('Expected ApiError not thrown');
        } catch (ApiError $apiError) {
            $this->assertSame(404, $apiError->getStatusCode());
        }
    }

    public function testGetCoursesWithAttributeFilter(): void
    {
        $filter = FilterTreeBuilder::create()
            ->iStartsWith('code', '44')
            ->createFilter();

        $options = [];
        Options::setFilter($options, $filter);
        $courses = $this->courseProvider->getCourses(1, 3, $options);
        $this->assertCount(2, $courses);
        $courseIds = array_map(static fn (Course $course) => $course->getIdentifier(), $courses);
        $this->assertContains('1', $courseIds);
        $this->assertContains('2', $courseIds);
    }

    public function testGetCoursesWithAttributeFilterNotFound(): void
    {
        $filter = FilterTreeBuilder::create()
            ->iStartsWith('code', 'foo')
            ->createFilter();

        $options = [];
        Options::setFilter($options, $filter);
        $courses = $this->courseProvider->getCourses(1, 3, $options);
        $this->assertCount(0, $courses);
    }

    public function testGetCoursesWithAttributeFilterDe(): void
    {
        $filter = FilterTreeBuilder::create()
            ->iContains('name', 'halb')
            ->createFilter();

        $options = [];
        Options::setLanguage($options, 'de');
        Options::setFilter($options, $filter);
        $courses = $this->courseProvider->getCourses(1, 3, $options);
        $this->assertCount(1, $courses);
        $course = $courses[0];
        $this->assertSame('3', $course->getIdentifier());
        $this->assertSame('Komputationshalbwissenschaft', $course->getName());
    }

    public function testGetCoursesWithAttributeFilterDeNotFound(): void
    {
        $filter = FilterTreeBuilder::create()
            ->iContains('name', 'unintelligence')
            ->createFilter();

        $options = [];
        Options::setLanguage($options, 'de');
        Options::setFilter($options, $filter);
        $courses = $this->courseProvider->getCourses(1, 3, $options);
        $this->assertCount(0, $courses);
    }

    public function testGetCoursesWithAttributeFilterEn(): void
    {
        $filter = FilterTreeBuilder::create()
            ->iContains('name', 'unintell')
            ->createFilter();

        $options = [];
        Options::setLanguage($options, 'en');
        Options::setFilter($options, $filter);
        $courses = $this->courseProvider->getCourses(1, 3, $options);
        $this->assertCount(1, $courses);
        $course = $courses[0];
        $this->assertSame('3', $course->getIdentifier());
        $this->assertSame('Computational Unintelligence', $course->getName());
    }

    public function testGetCoursesWithAttributeFilterEnNotFound(): void
    {
        $filter = FilterTreeBuilder::create()
            ->iContains('name', 'halb')
            ->createFilter();

        $options = [];
        Options::setLanguage($options, 'en');
        Options::setFilter($options, $filter);
        $courses = $this->courseProvider->getCourses(1, 3, $options);
        $this->assertCount(0, $courses);
    }

    public function testGetCoursesWithLocalDataAttributeFilter(): void
    {
        $filter = FilterTreeBuilder::create()
            ->equals(LocalData::BASE_PATH.self::COURSE_IDENTITY_CODE_UID_LOCAL_DATA_ATTRIBUTE_NAME, '1235')
            ->createFilter();

        $options = [];
        Options::setFilter($options, $filter);
        $courses = $this->courseProvider->getCourses(1, 3, $options);
        $this->assertCount(1, $courses);
        $course = $courses[0];
        $this->assertSame('2', $course->getIdentifier());
    }

    public function testGetCoursesWithLocalDataAttributeFilterNotFound(): void
    {
        $filter = FilterTreeBuilder::create()
            ->equals(LocalData::BASE_PATH.self::COURSE_IDENTITY_CODE_UID_LOCAL_DATA_ATTRIBUTE_NAME, 'foo')
            ->createFilter();

        $options = [];
        Options::setFilter($options, $filter);
        $courses = $this->courseProvider->getCourses(1, 3, $options);
        $this->assertCount(0, $courses);
    }

    public function testGetCoursesWithSearchParameterAndAttributeFilter(): void
    {
        $filter = FilterTreeBuilder::create()
            ->iStartsWith('code', '44')
            ->createFilter();

        $options = [
            Course::SEARCH_PARAMETER_NAME => 'besser',
        ];
        Options::setFilter($options, $filter);
        $courses = $this->courseProvider->getCourses(1, 3, $options);
        $this->assertCount(1, $courses);
        $course = $courses[0];
        $this->assertSame('1', $course->getIdentifier());
    }

    public function testGetCoursesWithSearchParameterAndAttributeFilterDe(): void
    {
        $filter = FilterTreeBuilder::create()
            ->iContains('name', 'halb')
            ->createFilter();

        $options = [
            Course::SEARCH_PARAMETER_NAME => 'wissen',
        ];
        Options::setLanguage($options, 'de');
        Options::setFilter($options, $filter);
        $courses = $this->courseProvider->getCourses(1, 3, $options);
        $this->assertCount(1, $courses);
        $course = $courses[0];
        $this->assertSame('3', $course->getIdentifier());
        $this->assertSame('Komputationshalbwissenschaft', $course->getName());
    }

    public function testGetCoursesWithSearchParameterAndAttributeFilterDeNotFound(): void
    {
        $filter = FilterTreeBuilder::create()
            ->iContains('name', 'halb')
            ->createFilter();

        $options = [
            Course::SEARCH_PARAMETER_NAME => 'unintelligence',
        ];
        Options::setLanguage($options, 'de');
        Options::setFilter($options, $filter);
        $courses = $this->courseProvider->getCourses(1, 3, $options);
        $this->assertCount(0, $courses);
    }

    public function testGetCoursesWithSearchParameterAndAttributeFilterEn(): void
    {
        $filter = FilterTreeBuilder::create()
            ->iEndsWith('code', 'A')
            ->createFilter();

        $options = [
            Course::SEARCH_PARAMETER_NAME => 'unintell',
        ];
        Options::setLanguage($options, 'en');
        Options::setFilter($options, $filter);
        $courses = $this->courseProvider->getCourses(1, 3, $options);
        $this->assertCount(1, $courses);
        $course = $courses[0];
        $this->assertSame('3', $course->getIdentifier());
        $this->assertSame('Computational Unintelligence', $course->getName());
    }

    public function testGetCoursesWithSearchParameterAndAttributeFilterEnNotFound(): void
    {
        $filter = FilterTreeBuilder::create()
            ->iEndsWith('code', 'A')
            ->createFilter();

        $options = [
            Course::SEARCH_PARAMETER_NAME => 'halb',
        ];
        Options::setLanguage($options, 'en');
        Options::setFilter($options, $filter);
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

    public function testGetCoursesWithLocalDataLecturersEn(): void
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

    public function testGetCourseByIdWithLocalDataLecturersEn(): void
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
        Options::setLanguage($options, 'en');
        $course = $this->courseProvider->getCourseById($courseUid, $options);

        $this->assertSame($courseUid, $course->getIdentifier());
        $this->assertSame('Computational Unintelligence', $course->getName());
        $this->assertSame('45_A', $course->getCode());
        $lecturers = $course->getLocalDataValue(self::LECTURERS_LOCAL_DATA_ATTRIBUTE_NAME);
        $this->assertCount(2, $lecturers);
        $lecturer = $lecturers[0];
        $this->assertSame('DEADBEEF2', $lecturer['personIdentifier']);
        $this->assertSame('PRIM_LEC', $lecturer['functionKey']);
        $this->assertSame('Main lecturer', $lecturer['functionName']);
    }

    public function testGetCourseByIdWithLecturersLocalDataAttributeDe(): void
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

    public function testGetCoursesWithLecturersLocalDataAttributeDe(): void
    {
        $coResponses = [
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
                            'functionKey' => 'PRIM_LEC',
                            'personUid' => 'DEADBEEF1',
                            'courseUid' => '2',
                        ],
                        [
                            'uid' => '44',
                            'functionKey' => 'SEC_LEC',
                            'personUid' => 'DEADBEEF3',
                            'courseUid' => '3',
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
        $courses = $this->courseProvider->getCourses(1, 30, $options);
        $this->assertCount(3, $courses);
        $course = $courses[0];
        $this->assertSame('1', $course->getIdentifier());
        $lecturers = $course->getLocalDataValue(self::LECTURERS_LOCAL_DATA_ATTRIBUTE_NAME);
        $this->assertSame([], $lecturers);
        $course = $courses[1];
        $this->assertSame('2', $course->getIdentifier());
        $lecturers = $course->getLocalDataValue(self::LECTURERS_LOCAL_DATA_ATTRIBUTE_NAME);
        $this->assertCount(1, $lecturers);
        $lecturer = $lecturers[0];
        $this->assertSame('DEADBEEF1', $lecturer['personIdentifier']);
        $this->assertSame('PRIM_LEC', $lecturer['functionKey']);
        $this->assertSame('Leiter*in', $lecturer['functionName']);
        $course = $courses[2];
        $this->assertSame('3', $course->getIdentifier());
        $lecturers = $course->getLocalDataValue(self::LECTURERS_LOCAL_DATA_ATTRIBUTE_NAME);
        $this->assertCount(2, $lecturers);
        $lecturer = $lecturers[0];
        $this->assertSame('DEADBEEF2', $lecturer['personIdentifier']);
        $this->assertSame('PRIM_LEC', $lecturer['functionKey']);
        $this->assertSame('Leiter*in', $lecturer['functionName']);
        $lecturer = $lecturers[1];
        $this->assertSame('DEADBEEF3', $lecturer['personIdentifier']);
        $this->assertSame('SEC_LEC', $lecturer['functionKey']);
    }

    public function testGetCoursesWithAttendeesLocalDataAttribute(): void
    {
        $this->mockResponses([
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'],
                json_encode([
                    'items' => [
                        [
                            'uid' => 'reg1',
                            'courseGroupUid' => 'group1',
                            'courseUid' => '1',
                            'lastModifiedAt' => '2026-02-06T18:40:51',
                            'personUid' => 'p1',
                            'registrationStatus' => 'FIX',
                        ],
                        [
                            'uid' => 'reg2',
                            'courseGroupUid' => 'group1',
                            'courseUid' => '1',
                            'lastModifiedAt' => '2026-02-11T11:39:16',
                            'personUid' => 'p2',
                            'registrationStatus' => 'FIX',
                        ],
                        [
                            'uid' => 'reg3',
                            'courseGroupUid' => 'group1',
                            'courseUid' => '1',
                            'lastModifiedAt' => '2026-02-11T11:39:16',
                            'personUid' => 'p3',
                            'registrationStatus' => 'WL',
                        ],
                        [
                            'uid' => 'reg4',
                            'courseGroupUid' => 'group2',
                            'courseUid' => '1',
                            'lastModifiedAt' => '2026-02-01T12:54:47',
                            'personUid' => 'p4',
                            'registrationStatus' => 'FIX',
                        ],
                        [
                            'uid' => 'reg4',
                            'courseGroupUid' => 'group3',
                            'courseUid' => '2',
                            'lastModifiedAt' => '2026-02-03T20:11:41',
                            'personUid' => 'p5',
                            'registrationStatus' => 'FIX',
                        ],
                    ],
                ])),
        ]);

        $options = [];
        Options::requestLocalDataAttributes($options, [self::ATTENDEES_LOCAL_DATA_ATTRIBUTE_NAME, self::WAITING_LIST_LOCAL_DATA_ATTRIBUTE_NAME]);
        $courses = $this->courseProvider->getCourses(1, 30, $options);
        $this->assertCount(3, $courses);

        $course = $courses[0];
        $this->assertSame('1', $course->getIdentifier());
        $attendees = $course->getLocalDataValue(self::ATTENDEES_LOCAL_DATA_ATTRIBUTE_NAME);
        $this->assertCount(3, $attendees);
        $this->assertSame('p1', $attendees[0]['personIdentifier']);
        $this->assertSame('p2', $attendees[1]['personIdentifier']);
        $this->assertSame('p4', $attendees[2]['personIdentifier']);
        $waitingList = $course->getLocalDataValue(self::WAITING_LIST_LOCAL_DATA_ATTRIBUTE_NAME);
        $this->assertCount(1, $waitingList);
        $this->assertSame('p3', $waitingList[0]['personIdentifier']);
        $course = $courses[1];
        $this->assertSame('2', $course->getIdentifier());
        $attendees = $course->getLocalDataValue(self::ATTENDEES_LOCAL_DATA_ATTRIBUTE_NAME);
        $this->assertCount(1, $attendees);
        $this->assertSame('p5', $attendees[0]['personIdentifier']);
        $waitingList = $course->getLocalDataValue(self::WAITING_LIST_LOCAL_DATA_ATTRIBUTE_NAME);
        $this->assertSame([], $waitingList);
        $course = $courses[2];
        $this->assertSame('3', $course->getIdentifier());
        $attendees = $course->getLocalDataValue(self::ATTENDEES_LOCAL_DATA_ATTRIBUTE_NAME);
        $this->assertSame([], $attendees);
        $waitingList = $course->getLocalDataValue(self::WAITING_LIST_LOCAL_DATA_ATTRIBUTE_NAME);
        $this->assertSame([], $waitingList);
    }

    public function testGetCoursesWithCourseGroupsLocalDataAttributeDe(): void
    {
        $this->mockResponses([
            // course registrations in course groups are deprecate: remove the following response
            // one they are removed from the course groups attribute
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'],
                json_encode([
                    'items' => [
                        [
                            'uid' => 'reg1',
                            'courseGroupUid' => 'group1',
                            'courseUid' => '1',
                            'lastModifiedAt' => '2026-02-06T18:40:51',
                            'personUid' => 'p1',
                            'registrationStatus' => 'FIX',
                        ],
                        [
                            'uid' => 'reg2',
                            'courseGroupUid' => 'group1',
                            'courseUid' => '1',
                            'lastModifiedAt' => '2026-02-11T11:39:16',
                            'personUid' => 'p2',
                            'registrationStatus' => 'FIX',
                        ],
                        [
                            'uid' => 'reg3',
                            'courseGroupUid' => 'group1',
                            'courseUid' => '1',
                            'lastModifiedAt' => '2026-02-11T11:39:16',
                            'personUid' => 'p3',
                            'registrationStatus' => 'WL',
                        ],
                        [
                            'uid' => 'reg4',
                            'courseGroupUid' => 'group2',
                            'courseUid' => '1',
                            'lastModifiedAt' => '2026-02-01T12:54:47',
                            'personUid' => 'p4',
                            'registrationStatus' => 'FIX',
                        ],
                        [
                            'uid' => 'reg4',
                            'courseGroupUid' => 'group3',
                            'courseUid' => '2',
                            'lastModifiedAt' => '2026-02-03T20:11:41',
                            'personUid' => 'p5',
                            'registrationStatus' => 'FIX',
                        ],
                    ],
                ])
            ),
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'],
                json_encode([
                    'items' => [
                        [
                            'uid' => '42',
                            'functionKey' => 'PRIM_LEC',
                            'personUid' => 'DEADBEEF2',
                            'courseUid' => '1',
                            'groups' => [
                                'items' => [
                                    [
                                        'groupUid' => 'group1',
                                    ],
                                ],
                            ],
                        ],
                        [
                            'uid' => '43',
                            'functionKey' => 'PRIM_LEC',
                            'personUid' => 'DEADBEEF1',
                            'courseUid' => '2',
                            'groups' => [
                                'items' => [
                                    [
                                        'groupUid' => 'group3',
                                    ],
                                ],
                            ],
                        ],
                        [
                            'uid' => '44',
                            'functionKey' => 'SEC_LEC',
                            'personUid' => 'DEADBEEF3',
                            'courseUid' => '1',
                            'groups' => [
                                'items' => [
                                    [
                                        'groupUid' => 'group2',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ])
            ),
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'],
                json_encode([
                    'items' => [
                        [
                            'uid' => 'group1',
                            'courseUid' => '1',
                            'name' => [
                                'value' => [
                                    'de' => 'Gruppe 1',
                                ],
                            ],
                        ],
                        [
                            'uid' => 'group2',
                            'courseUid' => '1',
                            'name' => [
                                'value' => [
                                    'en' => 'Group 2',
                                ],
                            ],
                        ],
                        [
                            'uid' => 'group3',
                            'courseUid' => '2',
                            'name' => [
                                'value' => [
                                    'de' => 'Gruppe 3',
                                    'en' => 'Group 3',
                                ],
                            ],
                        ],
                    ],
                ])
            ),
        ]);

        $options = [];
        Options::setLanguage($options, 'de');
        Options::requestLocalDataAttributes($options, [
            self::COURSE_GROUPS_LOCAL_DATA_ATTRIBUTE_NAME,
            self::COURSE_GROUP_REGISTRATIONS_LOCAL_DATA_ATTRIBUTE_NAME,
        ]);
        $courses = $this->courseProvider->getCourses(1, 30, $options);
        $this->assertCount(3, $courses);
        $course = $courses[0];
        $this->assertSame('1', $course->getIdentifier());
        $courseGroups = $course->getLocalDataValue(self::COURSE_GROUPS_LOCAL_DATA_ATTRIBUTE_NAME);
        $this->assertCount(2, $courseGroups);
        $courseGroup = $courseGroups[0];
        $this->assertSame('group1', $courseGroup['identifier']);
        $this->assertSame('Gruppe 1', $courseGroup['name']);
        $this->assertSame(['p1', 'p2'], $courseGroup['attendeeIdentifiers']);
        $courseGroup = $courseGroups[1];
        $this->assertSame('group2', $courseGroup['identifier']);
        $this->assertSame(null, $courseGroup['name']);
        $this->assertSame(['p4'], $courseGroup['attendeeIdentifiers']);
        $courseGroupRegistrations = $course->getLocalDataValue(self::COURSE_GROUP_REGISTRATIONS_LOCAL_DATA_ATTRIBUTE_NAME);
        $this->assertCount(2, $courseGroupRegistrations);
        $courseGroupRegistration = $courseGroupRegistrations[0];
        $this->assertSame('group1', $courseGroupRegistration['identifier']);
        $this->assertSame(['p1', 'p2'], $courseGroupRegistration['attendeeIdentifiers']);
        $this->assertSame(['p3'], $courseGroupRegistration['attendeeWaitingListIdentifiers']);
        $courseGroupRegistration = $courseGroupRegistrations[1];
        $this->assertSame('group2', $courseGroupRegistration['identifier']);
        $this->assertSame(['p4'], $courseGroupRegistration['attendeeIdentifiers']);
        $this->assertSame([], $courseGroupRegistration['attendeeWaitingListIdentifiers']);
        $course = $courses[1];
        $this->assertSame('2', $course->getIdentifier());
        $courseGroups = $course->getLocalDataValue(self::COURSE_GROUPS_LOCAL_DATA_ATTRIBUTE_NAME);
        $this->assertCount(1, $courseGroups);
        $courseGroup = $courseGroups[0];
        $this->assertSame('group3', $courseGroup['identifier']);
        $this->assertSame('Gruppe 3', $courseGroup['name']);
        $this->assertSame(['p5'], $courseGroup['attendeeIdentifiers']);
        $courseGroupRegistrations = $course->getLocalDataValue(self::COURSE_GROUP_REGISTRATIONS_LOCAL_DATA_ATTRIBUTE_NAME);
        $this->assertCount(1, $courseGroupRegistrations);
        $courseGroupRegistration = $courseGroupRegistrations[0];
        $this->assertSame('group3', $courseGroupRegistration['identifier']);
        $this->assertSame(['p5'], $courseGroupRegistration['attendeeIdentifiers']);
        $this->assertSame([], $courseGroupRegistration['attendeeWaitingListIdentifiers']);
        $course = $courses[2];
        $this->assertSame('3', $course->getIdentifier());
        $courseGroups = $course->getLocalDataValue(self::COURSE_GROUPS_LOCAL_DATA_ATTRIBUTE_NAME);
        $this->assertSame([], $courseGroups);
    }

    public function testGetCoursesWithCourseGroupsLocalDataAttributeEn(): void
    {
        $this->mockResponses([
            // course registrations in course groups are deprecate: remove the following response
            // one they are removed from the course groups attribute
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'],
                json_encode([
                    'items' => [
                        [
                            'uid' => 'reg1',
                            'courseGroupUid' => 'group1',
                            'courseUid' => '1',
                            'lastModifiedAt' => '2026-02-06T18:40:51',
                            'personUid' => 'p1',
                            'registrationStatus' => 'FIX',
                        ],
                        [
                            'uid' => 'reg2',
                            'courseGroupUid' => 'group1',
                            'courseUid' => '1',
                            'lastModifiedAt' => '2026-02-11T11:39:16',
                            'personUid' => 'p2',
                            'registrationStatus' => 'FIX',
                        ],
                        [
                            'uid' => 'reg3',
                            'courseGroupUid' => 'group1',
                            'courseUid' => '1',
                            'lastModifiedAt' => '2026-02-11T11:39:16',
                            'personUid' => 'p3',
                            'registrationStatus' => 'WL',
                        ],
                        [
                            'uid' => 'reg4',
                            'courseGroupUid' => 'group2',
                            'courseUid' => '1',
                            'lastModifiedAt' => '2026-02-01T12:54:47',
                            'personUid' => 'p4',
                            'registrationStatus' => 'FIX',
                        ],
                        [
                            'uid' => 'reg4',
                            'courseGroupUid' => 'group3',
                            'courseUid' => '2',
                            'lastModifiedAt' => '2026-02-03T20:11:41',
                            'personUid' => 'p5',
                            'registrationStatus' => 'FIX',
                        ],
                    ],
                ])
            ),
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'],
                json_encode([
                    'items' => [
                        [
                            'uid' => '42',
                            'functionKey' => 'PRIM_LEC',
                            'personUid' => 'DEADBEEF2',
                            'courseUid' => '1',
                            'groups' => [
                                'items' => [
                                    [
                                        'groupUid' => 'group1',
                                    ],
                                ],
                            ],
                        ],
                        [
                            'uid' => '43',
                            'functionKey' => 'PRIM_LEC',
                            'personUid' => 'DEADBEEF1',
                            'courseUid' => '2',
                            'groups' => [
                                'items' => [
                                    [
                                        'groupUid' => 'group3',
                                    ],
                                ],
                            ],
                        ],
                        [
                            'uid' => '44',
                            'functionKey' => 'SEC_LEC',
                            'personUid' => 'DEADBEEF3',
                            'courseUid' => '1',
                            'groups' => [
                                'items' => [
                                    [
                                        'groupUid' => 'group2',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ])
            ),
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'],
                json_encode([
                    'items' => [
                        [
                            'uid' => 'group1',
                            'courseUid' => '1',
                            'name' => [
                                'value' => [
                                    'de' => 'Gruppe 1',
                                ],
                            ],
                        ],
                        [
                            'uid' => 'group2',
                            'courseUid' => '1',
                            'name' => [
                                'value' => [
                                    'en' => 'Group 2',
                                ],
                            ],
                        ],
                        [
                            'uid' => 'group3',
                            'courseUid' => '2',
                            'name' => [
                                'value' => [
                                    'de' => 'Gruppe 3',
                                    'en' => 'Group 3',
                                ],
                            ],
                        ],
                    ],
                ])
            ),
        ]);

        $options = [];
        Options::setLanguage($options, 'en');
        Options::requestLocalDataAttributes($options, [
            self::COURSE_GROUPS_LOCAL_DATA_ATTRIBUTE_NAME,
            self::COURSE_GROUP_REGISTRATIONS_LOCAL_DATA_ATTRIBUTE_NAME,
        ]);
        $courses = $this->courseProvider->getCourses(1, 30, $options);
        $this->assertCount(3, $courses);
        $course = $courses[0];
        $this->assertSame('1', $course->getIdentifier());
        $courseGroups = $course->getLocalDataValue(self::COURSE_GROUPS_LOCAL_DATA_ATTRIBUTE_NAME);
        $this->assertCount(2, $courseGroups);
        $courseGroup = $courseGroups[0];
        $this->assertSame('group1', $courseGroup['identifier']);
        $this->assertSame(null, $courseGroup['name']);
        $this->assertSame(['p1', 'p2'], $courseGroup['attendeeIdentifiers']);
        $courseGroup = $courseGroups[1];
        $this->assertSame('group2', $courseGroup['identifier']);
        $this->assertSame('Group 2', $courseGroup['name']);
        $this->assertSame(['p4'], $courseGroup['attendeeIdentifiers']);
        $courseGroupRegistrations = $course->getLocalDataValue(self::COURSE_GROUP_REGISTRATIONS_LOCAL_DATA_ATTRIBUTE_NAME);
        $this->assertCount(2, $courseGroupRegistrations);
        $courseGroupRegistration = $courseGroupRegistrations[0];
        $this->assertSame('group1', $courseGroupRegistration['identifier']);
        $this->assertSame(['p1', 'p2'], $courseGroupRegistration['attendeeIdentifiers']);
        $this->assertSame(['p3'], $courseGroupRegistration['attendeeWaitingListIdentifiers']);
        $courseGroupRegistration = $courseGroupRegistrations[1];
        $this->assertSame('group2', $courseGroupRegistration['identifier']);
        $this->assertSame(['p4'], $courseGroupRegistration['attendeeIdentifiers']);
        $this->assertSame([], $courseGroupRegistration['attendeeWaitingListIdentifiers']);
        $course = $courses[1];
        $this->assertSame('2', $course->getIdentifier());
        $courseGroups = $course->getLocalDataValue(self::COURSE_GROUPS_LOCAL_DATA_ATTRIBUTE_NAME);
        $this->assertCount(1, $courseGroups);
        $courseGroup = $courseGroups[0];
        $this->assertSame('group3', $courseGroup['identifier']);
        $this->assertSame('Group 3', $courseGroup['name']);
        $this->assertSame(['p5'], $courseGroup['attendeeIdentifiers']);
        $courseGroupRegistrations = $course->getLocalDataValue(self::COURSE_GROUP_REGISTRATIONS_LOCAL_DATA_ATTRIBUTE_NAME);
        $this->assertCount(1, $courseGroupRegistrations);
        $courseGroupRegistration = $courseGroupRegistrations[0];
        $this->assertSame('group3', $courseGroupRegistration['identifier']);
        $this->assertSame(['p5'], $courseGroupRegistration['attendeeIdentifiers']);
        $this->assertSame([], $courseGroupRegistration['attendeeWaitingListIdentifiers']);
        $course = $courses[2];
        $this->assertSame('3', $course->getIdentifier());
        $courseGroups = $course->getLocalDataValue(self::COURSE_GROUPS_LOCAL_DATA_ATTRIBUTE_NAME);
        $this->assertSame([], $courseGroups);
    }

    public function testGetCourseByIdWithCourseGroupsLocalDataAttributeDe(): void
    {
        $courseUid = '1';
        $coResponses = [
            // course registrations in course groups are deprecate: remove the following response
            // once they are removed from the course groups attribute
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'],
                json_encode([
                    'items' => [
                        [
                            'uid' => 'reg1',
                            'courseGroupUid' => 'group1',
                            'courseUid' => $courseUid,
                            'lastModifiedAt' => '2026-02-06T18:40:51',
                            'personUid' => 'p1',
                            'registrationStatus' => 'FIX',
                        ],
                        [
                            'uid' => 'reg2',
                            'courseGroupUid' => 'group2',
                            'courseUid' => $courseUid,
                            'lastModifiedAt' => '2026-02-11T11:39:16',
                            'personUid' => 'p2',
                            'registrationStatus' => 'FIX',
                        ],
                        [
                            'uid' => 'reg3',
                            'courseGroupUid' => 'group1',
                            'courseUid' => $courseUid,
                            'lastModifiedAt' => '2026-02-11T11:39:16',
                            'personUid' => 'p3',
                            'registrationStatus' => 'WL',
                        ],
                    ],
                ])),
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'],
                json_encode([
                    'items' => [
                        [
                            'uid' => '42',
                            'functionKey' => 'PRIM_LEC',
                            'personUid' => 'DEADBEEF2',
                            'courseUid' => $courseUid,
                            'groups' => [
                                'items' => [
                                    [
                                        'groupUid' => 'group1',
                                    ],
                                ],
                            ],
                        ],
                        [
                            'uid' => '44',
                            'functionKey' => 'SEC_LEC',
                            'personUid' => 'DEADBEEF3',
                            'courseUid' => $courseUid,
                            'groups' => [
                                'items' => [
                                    [
                                        'groupUid' => 'group2',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ])),
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'],
                json_encode([
                    'items' => [
                        [
                            'uid' => 'group1',
                            'courseUid' => $courseUid,
                            'name' => [
                                'value' => [
                                    'de' => 'Gruppe 1',
                                ],
                            ],
                        ],
                        [
                            'uid' => 'group2',
                            'courseUid' => $courseUid,
                            'name' => [
                                'value' => [
                                    'en' => 'Group 2',
                                ],
                            ],
                        ],
                    ],
                ])),
        ];

        $this->mockResponses($coResponses);

        $options = [];
        Options::setLanguage($options, 'de');
        Options::requestLocalDataAttributes($options, [
            self::COURSE_GROUPS_LOCAL_DATA_ATTRIBUTE_NAME,
            self::COURSE_GROUP_REGISTRATIONS_LOCAL_DATA_ATTRIBUTE_NAME,
        ]);
        $course = $this->courseProvider->getCourseById($courseUid, $options);
        $this->assertSame($courseUid, $course->getIdentifier());
        $courseGroups = $course->getLocalDataValue(self::COURSE_GROUPS_LOCAL_DATA_ATTRIBUTE_NAME);
        $this->assertCount(2, $courseGroups);
        $courseGroup = $courseGroups[0];
        $this->assertSame('group1', $courseGroup['identifier']);
        $this->assertSame('Gruppe 1', $courseGroup['name']);
        $this->assertSame(['p1'], $courseGroup['attendeeIdentifiers']); // deprecate
        $courseGroup = $courseGroups[1];
        $this->assertSame('group2', $courseGroup['identifier']);
        $this->assertSame(null, $courseGroup['name']);
        $this->assertSame(['p2'], $courseGroup['attendeeIdentifiers']); // deprecate
        $courseGroupRegistrations = $course->getLocalDataValue(self::COURSE_GROUP_REGISTRATIONS_LOCAL_DATA_ATTRIBUTE_NAME);
        $this->assertCount(2, $courseGroupRegistrations);
        $courseGroupRegistration = $courseGroupRegistrations[0];
        $this->assertSame('group1', $courseGroupRegistration['identifier']);
        $this->assertSame(['p1'], $courseGroupRegistration['attendeeIdentifiers']);
        $this->assertSame(['p3'], $courseGroupRegistration['attendeeWaitingListIdentifiers']);
        $courseGroupRegistration = $courseGroupRegistrations[1];
        $this->assertSame('group2', $courseGroupRegistration['identifier']);
        $this->assertSame(['p2'], $courseGroupRegistration['attendeeIdentifiers']);
        $this->assertSame([], $courseGroupRegistration['attendeeWaitingListIdentifiers']);
    }

    public function testGetCourseByIdWithCourseGroupsLocalDataAttributeEn(): void
    {
        $courseUid = '1';
        $coResponses = [
            // course registrations in course groups are deprecate: remove the following response
            // once they are removed from the course groups attribute
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'],
                json_encode([
                    'items' => [
                        [
                            'uid' => 'reg1',
                            'courseGroupUid' => 'group1',
                            'courseUid' => $courseUid,
                            'lastModifiedAt' => '2026-02-06T18:40:51',
                            'personUid' => 'p1',
                            'registrationStatus' => 'FIX',
                        ],
                        [
                            'uid' => 'reg2',
                            'courseGroupUid' => 'group2',
                            'courseUid' => $courseUid,
                            'lastModifiedAt' => '2026-02-11T11:39:16',
                            'personUid' => 'p2',
                            'registrationStatus' => 'FIX',
                        ],
                        [
                            'uid' => 'reg3',
                            'courseGroupUid' => 'group1',
                            'courseUid' => $courseUid,
                            'lastModifiedAt' => '2026-02-11T11:39:16',
                            'personUid' => 'p3',
                            'registrationStatus' => 'WL',
                        ],
                    ],
                ])),
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'],
                json_encode([
                    'items' => [
                        [
                            'uid' => '42',
                            'functionKey' => 'PRIM_LEC',
                            'personUid' => 'DEADBEEF2',
                            'courseUid' => $courseUid,
                            'groups' => [
                                'items' => [
                                    [
                                        'groupUid' => 'group1',
                                    ],
                                ],
                            ],
                        ],
                        [
                            'uid' => '44',
                            'functionKey' => 'SEC_LEC',
                            'personUid' => 'DEADBEEF3',
                            'courseUid' => $courseUid,
                            'groups' => [
                                'items' => [
                                    [
                                        'groupUid' => 'group2',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ])),
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'],
                json_encode([
                    'items' => [
                        [
                            'uid' => 'group1',
                            'courseUid' => $courseUid,
                            'name' => [
                                'value' => [
                                    'de' => 'Gruppe 1',
                                ],
                            ],
                        ],
                        [
                            'uid' => 'group2',
                            'courseUid' => $courseUid,
                            'name' => [
                                'value' => [
                                    'en' => 'Group 2',
                                ],
                            ],
                        ],
                    ],
                ])),
        ];

        $this->mockResponses($coResponses);

        $options = [];
        Options::setLanguage($options, 'en');
        Options::requestLocalDataAttributes($options, [
            self::COURSE_GROUPS_LOCAL_DATA_ATTRIBUTE_NAME,
            self::COURSE_GROUP_REGISTRATIONS_LOCAL_DATA_ATTRIBUTE_NAME,
        ]);
        $course = $this->courseProvider->getCourseById($courseUid, $options);
        $this->assertSame($courseUid, $course->getIdentifier());
        $courseGroups = $course->getLocalDataValue(self::COURSE_GROUPS_LOCAL_DATA_ATTRIBUTE_NAME);
        $this->assertCount(2, $courseGroups);
        $courseGroup = $courseGroups[0];
        $this->assertSame('group1', $courseGroup['identifier']);
        $this->assertSame(null, $courseGroup['name']);
        $this->assertSame(['p1'], $courseGroup['attendeeIdentifiers']); // deprecate
        $courseGroup = $courseGroups[1];
        $this->assertSame('group2', $courseGroup['identifier']);
        $this->assertSame('Group 2', $courseGroup['name']);
        $this->assertSame(['p2'], $courseGroup['attendeeIdentifiers']); // deprecate
        $courseGroupRegistrations = $course->getLocalDataValue(self::COURSE_GROUP_REGISTRATIONS_LOCAL_DATA_ATTRIBUTE_NAME);
        $this->assertCount(2, $courseGroupRegistrations);
        $courseGroupRegistration = $courseGroupRegistrations[0];
        $this->assertSame('group1', $courseGroupRegistration['identifier']);
        $this->assertSame(['p1'], $courseGroupRegistration['attendeeIdentifiers']);
        $this->assertSame(['p3'], $courseGroupRegistration['attendeeWaitingListIdentifiers']);
        $courseGroupRegistration = $courseGroupRegistrations[1];
        $this->assertSame('group2', $courseGroupRegistration['identifier']);
        $this->assertSame(['p2'], $courseGroupRegistration['attendeeIdentifiers']);
        $this->assertSame([], $courseGroupRegistration['attendeeWaitingListIdentifiers']);
    }

    public function testGetCourseByIdWithAttendeeLocalDataAttributes(): void
    {
        $courseUid = '1';
        $coResponses = [
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'],
                json_encode([
                    'items' => [
                        [
                            'uid' => 'reg1',
                            'courseGroupUid' => 'group1',
                            'courseUid' => $courseUid,
                            'lastModifiedAt' => '2026-02-06T18:40:51',
                            'personUid' => 'p1',
                            'registrationStatus' => 'FIX',
                        ],
                        [
                            'uid' => 'reg2',
                            'courseGroupUid' => 'group2',
                            'courseUid' => $courseUid,
                            'lastModifiedAt' => '2026-02-11T11:39:16',
                            'personUid' => 'p2',
                            'registrationStatus' => 'FIX',
                        ],
                        [
                            'uid' => 'reg3',
                            'courseGroupUid' => 'group1',
                            'courseUid' => $courseUid,
                            'lastModifiedAt' => '2026-02-11T11:39:16',
                            'personUid' => 'p3',
                            'registrationStatus' => 'WL',
                        ],
                    ],
                ])),
        ];

        $this->mockResponses($coResponses);

        $options = [];
        Options::requestLocalDataAttributes($options, [self::ATTENDEES_LOCAL_DATA_ATTRIBUTE_NAME, self::WAITING_LIST_LOCAL_DATA_ATTRIBUTE_NAME]);
        $course = $this->courseProvider->getCourseById($courseUid, $options);

        $this->assertSame($courseUid, $course->getIdentifier());
        $attendees = $course->getLocalDataValue(self::ATTENDEES_LOCAL_DATA_ATTRIBUTE_NAME);
        $this->assertCount(2, $attendees);
        $this->assertSame('p1', $attendees[0]['personIdentifier']);
        $this->assertSame('p2', $attendees[1]['personIdentifier']);
        $waitingList = $course->getLocalDataValue(self::WAITING_LIST_LOCAL_DATA_ATTRIBUTE_NAME);
        $this->assertCount(1, $waitingList);
        $this->assertSame('p3', $waitingList[0]['personIdentifier']);
    }

    public function testGetCourseByIdWithExpectedPreviousKnowledgeLocalDataAttribute(): void
    {
        $descriptionJson = file_get_contents(__DIR__.'/course_descriptions_api_response.json');
        $coResponses = [
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'], $descriptionJson),
        ];

        $this->mockResponses($coResponses);

        $options = [];
        Options::requestLocalDataAttributes($options, [self::EXPECTED_PREVIOUS_KNOWLEDGE_LOCAL_DATA_ATTRIBUTE]);
        Options::setLanguage($options, 'de');

        $course = $this->courseProvider->getCourseById('1', $options);

        $this->assertSame('1', $course->getIdentifier());
        $this->assertSame('Keines', $course->getLocalDataValue(self::EXPECTED_PREVIOUS_KNOWLEDGE_LOCAL_DATA_ATTRIBUTE));
    }

    public function testGetCourseByIdWithTeachingMethodDescriptionLocalDataAttribute(): void
    {
        $descriptionJson = file_get_contents(__DIR__.'/course_descriptions_api_response.json');
        $coResponses = [
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'], $descriptionJson),
        ];

        $this->mockResponses($coResponses);

        $options = [];
        Options::requestLocalDataAttributes($options, [self::TEACHING_METHOD_DESCRIPTION_LOCAL_DATA_ATTRIBUTE]);
        Options::setLanguage($options, 'de');

        $course = $this->courseProvider->getCourseById('1', $options);

        $this->assertSame('1', $course->getIdentifier());
        $this->assertSame('interaktiv', $course->getLocalDataValue(self::TEACHING_METHOD_DESCRIPTION_LOCAL_DATA_ATTRIBUTE));
    }

    public function testGetCourseByIdWithTeachingMethodKeyLocalDataAttribute(): void
    {
        $descriptionJson = file_get_contents(__DIR__.'/course_descriptions_api_response.json');
        $coResponses = [
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'], $descriptionJson),
        ];

        $this->mockResponses($coResponses);

        $options = [];
        Options::requestLocalDataAttributes($options, [self::TEACHING_METHOD_KEY_LOCAL_DATA_ATTRIBUTE]);

        $course = $this->courseProvider->getCourseById('1', $options);

        $this->assertSame('1', $course->getIdentifier());
        $this->assertSame('VU', $course->getLocalDataValue(self::TEACHING_METHOD_KEY_LOCAL_DATA_ATTRIBUTE));
    }

    public function testGetCourseByIdWithExpectedPreviousKnowledgeLocalDataAttributeEn(): void
    {
        $descriptionJson = file_get_contents(__DIR__.'/course_descriptions_api_response.json');
        $coResponses = [
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'], $descriptionJson),
        ];

        $this->mockResponses($coResponses);

        $options = [];
        Options::requestLocalDataAttributes($options, [self::EXPECTED_PREVIOUS_KNOWLEDGE_LOCAL_DATA_ATTRIBUTE]);
        Options::setLanguage($options, 'en');

        $course = $this->courseProvider->getCourseById('1', $options);

        $this->assertSame('1', $course->getIdentifier());
        $this->assertSame('None', $course->getLocalDataValue(self::EXPECTED_PREVIOUS_KNOWLEDGE_LOCAL_DATA_ATTRIBUTE));
    }

    public function testGetCourseByIdWithTeachingMethodDescriptionLocalDataAttributeEn(): void
    {
        $descriptionJson = file_get_contents(__DIR__.'/course_descriptions_api_response.json');
        $coResponses = [
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'], $descriptionJson),
        ];

        $this->mockResponses($coResponses);

        $options = [];
        Options::requestLocalDataAttributes($options, [self::TEACHING_METHOD_DESCRIPTION_LOCAL_DATA_ATTRIBUTE]);
        Options::setLanguage($options, 'en');

        $course = $this->courseProvider->getCourseById('1', $options);

        $this->assertSame('1', $course->getIdentifier());
        $this->assertSame('interactive', $course->getLocalDataValue(self::TEACHING_METHOD_DESCRIPTION_LOCAL_DATA_ATTRIBUTE));
    }

    public function testGetCourseByIdWithDescriptionLocalDataAttribute(): void
    {
        $descriptionJson = file_get_contents(__DIR__.'/course_descriptions_api_response.json');
        $coResponses = [
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'], $descriptionJson),
        ];

        $this->mockResponses($coResponses);

        $options = [];
        Options::requestLocalDataAttributes($options, [self::DESCRIPTION_LOCAL_DATA_ATTRIBUTE]);
        Options::setLanguage($options, 'de');

        $course = $this->courseProvider->getCourseById('1', $options);

        $this->assertSame('1', $course->getIdentifier());
        $this->assertSame('Inhalt', $course->getLocalDataValue(self::DESCRIPTION_LOCAL_DATA_ATTRIBUTE));
    }

    public function testGetCourseByIdWithObjectiveLocalDataAttribute(): void
    {
        $descriptionJson = file_get_contents(__DIR__.'/course_descriptions_api_response.json');
        $coResponses = [
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'], $descriptionJson),
        ];

        $this->mockResponses($coResponses);

        $options = [];
        Options::requestLocalDataAttributes($options, [self::OBJECTIVE_LOCAL_DATA_ATTRIBUTE]);
        Options::setLanguage($options, 'de');

        $course = $this->courseProvider->getCourseById('1', $options);

        $this->assertSame('1', $course->getIdentifier());
        $this->assertSame('Weisheit', $course->getLocalDataValue(self::OBJECTIVE_LOCAL_DATA_ATTRIBUTE));
    }

    public function testGetCourseByIdWithDescriptionLocalDataAttributeEn(): void
    {
        $descriptionJson = file_get_contents(__DIR__.'/course_descriptions_api_response.json');
        $coResponses = [
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'], $descriptionJson),
        ];

        $this->mockResponses($coResponses);

        $options = [];
        Options::requestLocalDataAttributes($options, [self::DESCRIPTION_LOCAL_DATA_ATTRIBUTE]);
        Options::setLanguage($options, 'en');

        $course = $this->courseProvider->getCourseById('1', $options);

        $this->assertSame('1', $course->getIdentifier());
        $this->assertSame('Content', $course->getLocalDataValue(self::DESCRIPTION_LOCAL_DATA_ATTRIBUTE));
    }

    public function testGetCourseByIdWithObjectiveLocalDataAttributeEn(): void
    {
        $descriptionJson = file_get_contents(__DIR__.'/course_descriptions_api_response.json');
        $coResponses = [
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'], $descriptionJson),
        ];

        $this->mockResponses($coResponses);

        $options = [];
        Options::requestLocalDataAttributes($options, [self::OBJECTIVE_LOCAL_DATA_ATTRIBUTE]);
        Options::setLanguage($options, 'en');

        $course = $this->courseProvider->getCourseById('1', $options);

        $this->assertSame('1', $course->getIdentifier());
        $this->assertSame('Wisdom', $course->getLocalDataValue(self::OBJECTIVE_LOCAL_DATA_ATTRIBUTE));
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

    public function testGetCourseEventByIdentifier(): void
    {
        $this->mockResponses([
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'],
                file_get_contents(__DIR__.'/appointment_api_item_response.json')),
        ]);

        $courseEvent = $this->courseProvider->getCourseEventById('1');
        $this->assertSame('1', $courseEvent->getIdentifier());
        $this->assertSame('42', $courseEvent->getCourseIdentifier());
        $this->assertEquals(new \DateTimeImmutable('2026-03-11T10:15:00', new \DateTimeZone('Europe/Vienna')), $courseEvent->getStartAt());
        $this->assertEquals(new \DateTimeImmutable('2026-03-11T11:45:00', new \DateTimeZone('Europe/Vienna')), $courseEvent->getEndAt());
        $this->assertSame('CLASS', $courseEvent->getTypeKey());
    }

    public function testGetCourseEventByIdentifierNotFound(): void
    {
        $this->mockResponses([
            new Response(404, ['Content-Type' => 'application/json;charset=utf-8'], ''),
        ]);

        try {
            $this->courseProvider->getCourseEventById('non-existing-id');
        } catch (ApiError $e) {
            $this->assertEquals(404, $e->getStatusCode());
        }
    }

    public function testGetCourseEventByIdentifierWithLocalData(): void
    {
        $this->mockResponses([
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'],
                file_get_contents(__DIR__.'/appointment_api_item_response.json')),
        ]);

        $options = [];
        Options::requestLocalDataAttributes($options, [
            self::COURSE_EVENT_COMMENT_LOCAL_DATA_ATTRIBUTE,
            self::COURSE_EVENT_ROOM_UID_LOCAL_DATA_ATTRIBUTE,
            self::COURSE_EVENT_EVENT_TYPE_KEY_LOCAL_DATA_ATTRIBUTE,
        ]);

        $courseEvent = $this->courseProvider->getCourseEventById('1', $options);
        $this->assertSame('1', $courseEvent->getIdentifier());
        $this->assertSame('First class', $courseEvent->getLocalDataValue(self::COURSE_EVENT_COMMENT_LOCAL_DATA_ATTRIBUTE));
        $this->assertSame('2', $courseEvent->getLocalDataValue(self::COURSE_EVENT_ROOM_UID_LOCAL_DATA_ATTRIBUTE));
        $this->assertSame(AppointmentResource::REGULAR_CLASS_EVENT_TYPE_KEY, $courseEvent->getLocalDataValue(self::COURSE_EVENT_EVENT_TYPE_KEY_LOCAL_DATA_ATTRIBUTE));
    }

    public function testGetCourseEvents(): void
    {
        $this->mockResponses([
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'],
                file_get_contents(__DIR__.'/appointment_api_collection_response.json')),
        ]);

        $courseEvents = $this->courseProvider->getCourseEventsByCourseId(
            '42', 1, 10);

        $this->assertCount(4, $courseEvents);
        $courseEvent = $courseEvents[0];
        $this->assertSame('1', $courseEvent->getIdentifier());
        $this->assertSame('42', $courseEvent->getCourseIdentifier());
        $this->assertEquals(new \DateTimeImmutable('2026-03-11T10:15:00', new \DateTimeZone('Europe/Vienna')), $courseEvent->getStartAt());
        $this->assertEquals(new \DateTimeImmutable('2026-03-11T11:45:00', new \DateTimeZone('Europe/Vienna')), $courseEvent->getEndAt());
        $this->assertSame('CLASS', $courseEvent->getTypeKey());
        $courseEvent = $courseEvents[1];
        $this->assertSame('2', $courseEvent->getIdentifier());
        $this->assertSame('42', $courseEvent->getCourseIdentifier());
        $this->assertEquals(new \DateTimeImmutable('2026-03-18T10:15:00', new \DateTimeZone('Europe/Vienna')), $courseEvent->getStartAt());
        $this->assertEquals(new \DateTimeImmutable('2026-03-18T11:45:00', new \DateTimeZone('Europe/Vienna')), $courseEvent->getEndAt());
        $this->assertSame('CLASS', $courseEvent->getTypeKey());
        $courseEvent = $courseEvents[2];
        $this->assertSame('3', $courseEvent->getIdentifier());
        $this->assertSame('42', $courseEvent->getCourseIdentifier());
        $this->assertEquals(new \DateTimeImmutable('2026-03-25T10:15:00', new \DateTimeZone('Europe/Vienna')), $courseEvent->getStartAt());
        $this->assertEquals(new \DateTimeImmutable('2026-03-25T11:45:00', new \DateTimeZone('Europe/Vienna')), $courseEvent->getEndAt());
        $this->assertSame('CLASS', $courseEvent->getTypeKey());
        $courseEvent = $courseEvents[3];
        $this->assertSame('4', $courseEvent->getIdentifier());
        $this->assertSame('42', $courseEvent->getCourseIdentifier());
        $this->assertEquals(new \DateTimeImmutable('2026-06-25T13:00:00', new \DateTimeZone('Europe/Vienna')), $courseEvent->getStartAt());
        $this->assertEquals(new \DateTimeImmutable('2026-06-25T15:00:00', new \DateTimeZone('Europe/Vienna')), $courseEvent->getEndAt());
        $this->assertSame('EXAM', $courseEvent->getTypeKey());
    }

    public function testGetCourseEventsWithExamTypeKeyFilter(): void
    {
        $this->mockResponses([
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'],
                file_get_contents(__DIR__.'/appointment_api_collection_response.json')),
        ]);

        $courseEvents = $this->courseProvider->getCourseEventsByCourseId(
            '42', 1, 10,
            [
                CourseEvent::TYPE_KEY_QUERY_PARAMETER => 'EXAM',
            ]);

        $this->assertCount(1, $courseEvents);
        $courseEvent = $courseEvents[0];
        $this->assertSame('4', $courseEvent->getIdentifier());
        $this->assertSame('42', $courseEvent->getCourseIdentifier());
        $this->assertEquals(new \DateTimeImmutable('2026-06-25T13:00:00', new \DateTimeZone('Europe/Vienna')), $courseEvent->getStartAt());
        $this->assertEquals(new \DateTimeImmutable('2026-06-25T15:00:00', new \DateTimeZone('Europe/Vienna')), $courseEvent->getEndAt());
        $this->assertSame('EXAM', $courseEvent->getTypeKey());
    }

    public function testGetCourseEventsWithClassTypeKeyFilter(): void
    {
        $this->mockResponses([
            new Response(200, ['Content-Type' => 'application/json;charset=utf-8'],
                file_get_contents(__DIR__.'/appointment_api_collection_response.json')),
        ]);

        $courseEvents = $this->courseProvider->getCourseEventsByCourseId(
            '42', 1, 10,
            [
                CourseEvent::TYPE_KEY_QUERY_PARAMETER => 'CLASS',
            ]);

        $this->assertCount(3, $courseEvents);
        $courseEvent = $courseEvents[0];
        $this->assertSame('1', $courseEvent->getIdentifier());
        $this->assertSame('42', $courseEvent->getCourseIdentifier());
        $this->assertEquals(new \DateTimeImmutable('2026-03-11T10:15:00', new \DateTimeZone('Europe/Vienna')), $courseEvent->getStartAt());
        $this->assertEquals(new \DateTimeImmutable('2026-03-11T11:45:00', new \DateTimeZone('Europe/Vienna')), $courseEvent->getEndAt());
        $this->assertSame('CLASS', $courseEvent->getTypeKey());
        $courseEvent = $courseEvents[1];
        $this->assertSame('2', $courseEvent->getIdentifier());
        $this->assertSame('42', $courseEvent->getCourseIdentifier());
        $this->assertEquals(new \DateTimeImmutable('2026-03-18T10:15:00', new \DateTimeZone('Europe/Vienna')), $courseEvent->getStartAt());
        $this->assertEquals(new \DateTimeImmutable('2026-03-18T11:45:00', new \DateTimeZone('Europe/Vienna')), $courseEvent->getEndAt());
        $this->assertSame('CLASS', $courseEvent->getTypeKey());
        $courseEvent = $courseEvents[2];
        $this->assertSame('3', $courseEvent->getIdentifier());
        $this->assertSame('42', $courseEvent->getCourseIdentifier());
        $this->assertEquals(new \DateTimeImmutable('2026-03-25T10:15:00', new \DateTimeZone('Europe/Vienna')), $courseEvent->getStartAt());
        $this->assertEquals(new \DateTimeImmutable('2026-03-25T11:45:00', new \DateTimeZone('Europe/Vienna')), $courseEvent->getEndAt());
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
        $this->mockResponses($coResponses);
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
        } finally {
            $this->courseProvider->reset();
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
                'entity_short_name' => 'BaseCourse',
            ],
            [
                'local_data_attribute' => self::SEMESTER_LOCAL_DATA_ATTRIBUTE_NAME,
                'source_attribute' => self::SEMESTER_SOURCE_ATTRIBUTE_NAME,
                'entity_short_name' => 'BaseCourse',
            ],
            [
                'local_data_attribute' => self::COURSE_IDENTITY_CODE_UID_LOCAL_DATA_ATTRIBUTE_NAME,
                'source_attribute' => self::COURSE_IDENTITY_CODE_UID_SOURCE_ATTRIBUTE_NAME,
                'entity_short_name' => 'BaseCourse',
            ],
            [
                'local_data_attribute' => self::EXPECTED_PREVIOUS_KNOWLEDGE_LOCAL_DATA_ATTRIBUTE,
                'source_attribute' => CourseEventSubscriber::EXPECTED_PREVIOUS_KNOWLEDGE_SOURCE_DATA_ATTRIBUTE,
                'entity_short_name' => 'BaseCourse',
            ],
            [
                'local_data_attribute' => self::TEACHING_METHOD_DESCRIPTION_LOCAL_DATA_ATTRIBUTE,
                'source_attribute' => CourseEventSubscriber::TEACHING_METHOD_DESCRIPTION_SOURCE_DATA_ATTRIBUTE,
                'entity_short_name' => 'BaseCourse',
            ],
            [
                'local_data_attribute' => self::TEACHING_METHOD_KEY_LOCAL_DATA_ATTRIBUTE,
                'source_attribute' => CourseEventSubscriber::TEACHING_METHOD_KEY_SOURCE_DATA_ATTRIBUTE,
                'entity_short_name' => 'BaseCourse',
            ],
            [
                'local_data_attribute' => self::DESCRIPTION_LOCAL_DATA_ATTRIBUTE,
                'source_attribute' => CourseEventSubscriber::DESCRIPTION_SOURCE_DATA_ATTRIBUTE,
                'entity_short_name' => 'BaseCourse',
            ],
            [
                'local_data_attribute' => self::OBJECTIVE_LOCAL_DATA_ATTRIBUTE,
                'source_attribute' => CourseEventSubscriber::OBJECTIVE_SOURCE_DATA_ATTRIBUTE,
                'entity_short_name' => 'BaseCourse',
            ],
            [
                'local_data_attribute' => self::LECTURERS_LOCAL_DATA_ATTRIBUTE_NAME,
                'source_attribute' => CourseEventSubscriber::LECTURERS_SOURCE_DATA_ATTRIBUTE,
                'entity_short_name' => 'BaseCourse',
            ],
            [
                'local_data_attribute' => self::ATTENDEES_LOCAL_DATA_ATTRIBUTE_NAME,
                'source_attribute' => CourseEventSubscriber::ATTENDEES_SOURCE_DATA_ATTRIBUTE,
                'entity_short_name' => 'BaseCourse',
            ],
            [
                'local_data_attribute' => self::WAITING_LIST_LOCAL_DATA_ATTRIBUTE_NAME,
                'source_attribute' => CourseEventSubscriber::ATTENDEE_WAITING_LIST_SOURCE_DATA_ATTRIBUTE,
                'entity_short_name' => 'BaseCourse',
            ],
            [
                'local_data_attribute' => self::COURSE_GROUPS_LOCAL_DATA_ATTRIBUTE_NAME,
                'source_attribute' => CourseEventSubscriber::COURSE_GROUPS_SOURCE_DATA_ATTRIBUTE,
                'entity_short_name' => 'BaseCourse',
            ],
            [
                'local_data_attribute' => self::COURSE_GROUP_REGISTRATIONS_LOCAL_DATA_ATTRIBUTE_NAME,
                'source_attribute' => CourseEventSubscriber::COURSE_GROUP_REGISTRATIONS_SOURCE_DATA_ATTRIBUTE,
                'entity_short_name' => 'BaseCourse',
            ],
            [
                'local_data_attribute' => self::COURSE_EVENT_COMMENT_LOCAL_DATA_ATTRIBUTE,
                'source_attribute' => CourseEventEventSubscriber::COMMENT_SOURCE_DATA_ATTRIBUTE,
                'entity_short_name' => 'BaseCourseEvent',
            ],
            [
                'local_data_attribute' => self::COURSE_EVENT_ROOM_UID_LOCAL_DATA_ATTRIBUTE,
                'source_attribute' => CourseEventEventSubscriber::ROOM_UID_SOURCE_DATA_ATTRIBUTE,
                'entity_short_name' => 'BaseCourseEvent',
            ],
            [
                'local_data_attribute' => self::COURSE_EVENT_EVENT_TYPE_KEY_LOCAL_DATA_ATTRIBUTE,
                'source_attribute' => CourseEventEventSubscriber::EVENT_TYPE_KEY_SOURCE_DATA_ATTRIBUTE,
                'entity_short_name' => 'BaseCourseEvent',
            ],
        ];

        return $config;
    }
}
