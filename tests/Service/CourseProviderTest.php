<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Tests\Service;

use Dbp\CampusonlineApi\LegacyWebService\ApiException;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\EventSubscriber\CourseEventSubscriber;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service\CourseApi;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service\CourseProvider;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\Rest\Options;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\EventDispatcher\EventDispatcher;

class CourseProviderTest extends TestCase
{
    private const COURSE_TYPE_ATTRIBUTE_NAME = 'type';

    private ?CourseProvider $courseProvider = null;
    private ?CourseApi $courseApi = null;

    private static function createConfig(): array
    {
        $config = [];
        $config['local_data_mapping'] = [
            [
                'local_data_attribute' => self::COURSE_TYPE_ATTRIBUTE_NAME,
                'source_attribute' => self::COURSE_TYPE_ATTRIBUTE_NAME,
                'default_value' => '',
            ],
        ];

        return $config;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $eventDispatcher = new EventDispatcher();
        $localDataEventSubscriber = new CourseEventSubscriber();
        $localDataEventSubscriber->setConfig(self::createConfig());
        $eventDispatcher->addSubscriber($localDataEventSubscriber);

        $this->courseApi = new CourseApi();
        $this->courseApi->setConfig(['org_root_id' => '1']); // some value is required
        $this->courseApi->setCache(new ArrayAdapter(), 3600);
        $this->courseProvider = new CourseProvider($this->courseApi, $eventDispatcher);
        $this->mockResponses([]);
    }

    private function mockResponses(array $responses): void
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $this->courseApi->setClientHandler($stack);
    }

    public function testGetCourses()
    {
        $this->mockResponses([
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'], file_get_contents(__DIR__.'/courses_by_organization_response.xml')),
        ]);

        $courses = $this->courseProvider->getCourses(1, 50);
        $this->assertCount(34, $courses);
        $course = $courses[0];
        $this->assertSame('241333', $course->getIdentifier());
        $this->assertSame('Technische Informatik 1', $course->getName());
    }

    public function testGetCoursesPagination()
    {
        $this->mockResponses([
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'], file_get_contents(__DIR__.'/courses_by_organization_response.xml')),
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

    public function testGetCourses500()
    {
        $this->mockResponses([
            new Response(500, ['Content-Type' => 'text/xml;charset=utf-8'], ''),
        ]);

        $this->expectException(ApiError::class);
        $this->courseProvider->getCourses(1, 50);
    }

    public function testGetCoursesInvalidXML()
    {
        $this->mockResponses([
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'], file_get_contents(__DIR__.'/course_response_invalid_xml.xml')),
        ]);

        $this->expectException(ApiException::class);
        $this->courseProvider->getCourses(1, 50);
    }

    public function testGetCourseById()
    {
        $this->mockResponses([
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'], file_get_contents(__DIR__.'/course_by_id_response.xml')),
        ]);

        $course = $this->courseProvider->getCourseById('240759');

        $this->assertSame('240759', $course->getIdentifier());
        $this->assertSame('Computational Intelligence', $course->getName());
        $this->assertSame('442071', $course->getCode());
    }

    public function testGetCourseByIdNotFound()
    {
        $this->mockResponses([
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'], file_get_contents(__DIR__.'/course_by_id_response.xml')),
        ]);

        try {
            $this->courseProvider->getCourseById('404');
            $this->fail('Expected an ApiError to be thrown');
        } catch (\Throwable $exception) {
            $this->assertInstanceOf(ApiError::class, $exception);
            $this->assertEquals(404, $exception->getStatusCode());
        }
    }

    public function testGetCourseById503()
    {
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

    public function testGetCoursesByOrganization()
    {
        $this->mockResponses([
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'], file_get_contents(__DIR__.'/courses_by_organization_response.xml')),
        ]);

        $courses = $this->courseProvider->getCourses(1, 50, ['organization' => '2337']);
        $this->assertCount(34, $courses);

        $course = $courses[0];
        $this->assertSame('241333', $course->getIdentifier());
        $this->assertSame('Technische Informatik 1', $course->getName());
        $this->assertSame('448001', $course->getCode());
    }

    public function testGetCourseLocalData()
    {
        $this->mockResponses([
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'], file_get_contents(__DIR__.'/course_by_id_response.xml')),
        ]);

        $options = [];
        Options::requestLocalDataAttributes($options, [self::COURSE_TYPE_ATTRIBUTE_NAME]);
        $course = $this->courseProvider->getCourseById('240759', $options);

        $this->assertSame('240759', $course->getIdentifier());
        $this->assertSame('Computational Intelligence', $course->getName());
        $this->assertSame('UE', $course->getLocalDataValue(self::COURSE_TYPE_ATTRIBUTE_NAME));
    }

    public function testGetCoursesSearchParameter(): void
    {
        $this->mockResponses([
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'], file_get_contents(__DIR__.'/courses_by_organization_response.xml')),
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
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'], file_get_contents(__DIR__.'/courses_by_organization_response.xml')),
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
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'], file_get_contents(__DIR__.'/courses_by_organization_response.xml')),
        ]);

        $courses = $this->courseProvider->getCourses(1, 50, ['search' => 'foobar']);
        $this->assertEquals([], $courses);
    }

    public function testGetCoursesSearchParameterPagination()
    {
        $this->mockResponses([
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'], file_get_contents(__DIR__.'/courses_by_organization_response.xml')),
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
}
