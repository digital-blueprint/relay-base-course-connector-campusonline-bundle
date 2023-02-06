<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Tests\Service;

use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\EventSubscriber\CourseEventSubscriber;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service\CourseApi;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service\CourseProvider;
use Dbp\Relay\BasePersonBundle\Service\DummyPersonProvider;
use Dbp\Relay\CoreBundle\Authorization\AuthorizationDataMuxer;
use Dbp\Relay\CoreBundle\Authorization\AuthorizationDataProviderProvider;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use Dbp\Relay\CoreBundle\LocalData\LocalData;
use Dbp\Relay\CoreBundle\TestUtils\TestUserSession;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

class CourseProviderTest extends TestCase
{
    private const COURSE_CODE_ATTRIBUTE_NAME = 'code';

    /** @var CourseProvider */
    private $courseProvider;

    /** @var CourseApi */
    private $courseApi;

    private static function createConfig(): array
    {
        $config = [];
        $config['local_data_mapping'] = [
            [
                'local_data_attribute' => self::COURSE_CODE_ATTRIBUTE_NAME,
                'source_attribute' => self::COURSE_CODE_ATTRIBUTE_NAME,
                'authorization_expression' => 'true',
                'allow_query' => false,
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
        $localDataEventSubscriber->_injectServices(new TestUserSession('testuser'), new AuthorizationDataMuxer(new AuthorizationDataProviderProvider([]), new EventDispatcher()));
        $localDataEventSubscriber->setConfig(self::createConfig());
        $eventDispatcher->addSubscriber($localDataEventSubscriber);

        $this->courseApi = new CourseApi();
        $this->courseApi->setConfig(['org_root_id' => '1']); // some value is required
        $this->courseProvider = new CourseProvider($this->courseApi, $eventDispatcher, new DummyPersonProvider());
        $this->mockResponses([]);
    }

    private function mockResponses(array $responses)
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
        $this->assertSame('VO', $course->getType());
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

        $this->expectException(ApiError::class);
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
        $this->assertSame('UE', $course->getType());
    }

    public function testGetCourseByIdNotFound()
    {
        $this->mockResponses([
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'], file_get_contents(__DIR__.'/course_by_id_response.xml')),
        ]);

        try {
            $this->courseProvider->getCourseById('---');
        } catch (\Throwable $exception) {
            $this->assertInstanceOf(ApiError::class, $exception);
            $this->assertEquals(404, $exception->getStatusCode());
        }
    }

    public function testGetCourseById500()
    {
        $this->mockResponses([
            new Response(500, ['Content-Type' => 'text/xml;charset=utf-8'], ''),
        ]);

        try {
            $this->courseProvider->getCourseById('240759');
        } catch (\Throwable $exception) {
            $this->assertInstanceOf(ApiError::class, $exception);
            $this->assertEquals(500, $exception->getStatusCode());
        }
    }

    public function testGetCoursesByOrganization()
    {
        $this->mockResponses([
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'], file_get_contents(__DIR__.'/courses_by_organization_response.xml')),
        ]);

        $courses = $this->courseProvider->getCourses(1, 50, ['queryLocal' => 'organization:2337']);
        $this->assertCount(34, $courses);

        $course = $courses[0];
        $this->assertSame('241333', $course->getIdentifier());
        $this->assertSame('Technische Informatik 1', $course->getName());
        $this->assertSame('VO', $course->getType());
    }

    public function testGetCourseLocalData()
    {
        $this->mockResponses([
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'], file_get_contents(__DIR__.'/course_by_id_response.xml')),
        ]);

        $options = [];
        LocalData::addIncludeParameter($options, [self::COURSE_CODE_ATTRIBUTE_NAME]);
        $course = $this->courseProvider->getCourseById('240759', $options);

        $this->assertSame('240759', $course->getIdentifier());
        $this->assertSame('Computational Intelligence', $course->getName());
        $this->assertSame('UE', $course->getType());
        $this->assertSame('442071', $course->getLocalDataValue(self::COURSE_CODE_ATTRIBUTE_NAME));
    }
}
