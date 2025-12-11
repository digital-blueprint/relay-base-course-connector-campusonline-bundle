<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Tests\Service;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Apis\PublicRestCourseApi;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\DependencyInjection\Configuration;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\DependencyInjection\DbpRelayBaseCourseConnectorCampusonlineExtension;
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
    private const COURSE_TYPE_SOURCE_ATTRIBUTE_NAME = 'type';
    private const TEACHING_TERM_LOCAL_DATA_ATTRIBUTE_NAME = 'term';
    private const TEACHING_TERM_SOURCE_ATTRIBUTE_NAME = 'teachingTerm';

    private ?CourseProvider $courseProvider = null;
    private ?EventDispatcher $eventDispatcher = null;
    private ?EntityManagerInterface $entityManager = null;

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
                'local_data_attribute' => self::TEACHING_TERM_LOCAL_DATA_ATTRIBUTE_NAME,
                'source_attribute' => self::TEACHING_TERM_SOURCE_ATTRIBUTE_NAME,
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

        $this->courseProvider = new CourseProvider($this->entityManager, $this->eventDispatcher);
        $this->courseProvider->setConfig($this->getPublicRestApiConfig());
        $this->courseProvider->setCache(new ArrayAdapter(), 3600);
        $this->courseProvider->setLogger(new NullLogger());

        $localDataEventSubscriber = new CourseEventSubscriber($this->courseProvider);
        $localDataEventSubscriber->setConfig(self::createEventSubscriberConfig());
        $this->eventDispatcher->addSubscriber($localDataEventSubscriber);
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
        $coResponses = [
            new Response(200, ['Content-Type' => 'applicateion/json;charset=utf-8'],
                json_encode([
                    'uid' => '240759',
                    'title' => [
                        'value' => [
                            'en' => 'Computational Intelligence',
                            'de' => 'Komputationswissenschaft',
                        ],
                    ],
                ])),
        ];

        $options = [];
        Options::setLanguage($options, 'en');
        $this->mockResponses($coResponses, true);
        $course = $this->courseProvider->getCourseById('240759', $options);
        $this->assertSame('240759', $course->getIdentifier());
        $this->assertSame('Computational Intelligence', $course->getName());
    }

    public function testGetCourseByIdentifierDe(): void
    {
        $coResponses = [
            new Response(200, ['Content-Type' => 'applicateion/json;charset=utf-8'],
                json_encode([
                    'uid' => '240759',
                    'title' => [
                        'value' => [
                            'en' => 'Computational Intelligence',
                            'de' => 'Komputationswissenschaft',
                        ],
                    ],
                ])),
        ];

        $options = [];
        Options::setLanguage($options, 'de');
        $this->mockResponses($coResponses, true);
        $course = $this->courseProvider->getCourseById('240759', $options);
        $this->assertSame('240759', $course->getIdentifier());
        $this->assertSame('Komputationswissenschaft', $course->getName());
    }

    public function testGetCoursesLegacy(): void
    {
        $this->courseProvider->setConfig($this->getLegacyApiConfig());

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
        $this->courseProvider->setConfig($this->getLegacyApiConfig());

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
        $this->courseProvider->setConfig($this->getLegacyApiConfig());

        $this->mockResponses([
            new Response(500, ['Content-Type' => 'text/xml;charset=utf-8'], ''),
        ]);

        $this->expectException(ApiError::class);
        $this->courseProvider->getCourses(1, 50);
    }

    public function testGetCoursesInvalidXMLLegacy(): void
    {
        $this->courseProvider->setConfig($this->getLegacyApiConfig());

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
        $this->courseProvider->setConfig($this->getLegacyApiConfig());

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
        $this->courseProvider->setConfig($this->getLegacyApiConfig());

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
        $this->courseProvider->setConfig($this->getLegacyApiConfig());

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
        $this->courseProvider->setConfig($this->getLegacyApiConfig());

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
        $this->courseProvider->setConfig($this->getLegacyApiConfig());

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
        $this->courseProvider->setConfig($this->getLegacyApiConfig());

        $this->mockResponses([
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'],
                file_get_contents(__DIR__.'/course_by_id_response.xml')),
        ]);

        $options = [];
        Options::requestLocalDataAttributes($options,
            [self::COURSE_TYPE_LOCAL_DATA_ATTRIBUTE_NAME, self::TEACHING_TERM_LOCAL_DATA_ATTRIBUTE_NAME]);
        $course = $this->courseProvider->getCourseById('240759', $options);

        $this->assertSame('240759', $course->getIdentifier());
        $this->assertSame('Computational Intelligence', $course->getName());
        $this->assertSame('UE', $course->getLocalDataValue(self::COURSE_TYPE_LOCAL_DATA_ATTRIBUTE_NAME));
        $this->assertSame('Sommersemester 2021',
            $course->getLocalDataValue(self::TEACHING_TERM_LOCAL_DATA_ATTRIBUTE_NAME));
    }

    public function testGetCoursesLocalDataLegacy(): void
    {
        $this->courseProvider->setConfig($this->getLegacyApiConfig());

        $this->mockResponses([
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'],
                file_get_contents(__DIR__.'/courses_by_organization_response.xml')),
        ]);

        $options = [];
        Options::requestLocalDataAttributes($options,
            [self::COURSE_TYPE_LOCAL_DATA_ATTRIBUTE_NAME, self::TEACHING_TERM_LOCAL_DATA_ATTRIBUTE_NAME]);
        $courses = $this->courseProvider->getCourses(1, 50, $options);
        $this->assertCount(34, $courses);

        foreach ($courses as $course) {
            $this->assertNotNull($course->getLocalDataValue(self::COURSE_TYPE_LOCAL_DATA_ATTRIBUTE_NAME));
            $this->assertNotNull($course->getLocalDataValue(self::TEACHING_TERM_LOCAL_DATA_ATTRIBUTE_NAME));
        }
    }

    public function testGetCoursesSearchParameterLegacy(): void
    {
        $this->courseProvider->setConfig($this->getLegacyApiConfig());

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
        $this->courseProvider->setConfig($this->getLegacyApiConfig());

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
}
