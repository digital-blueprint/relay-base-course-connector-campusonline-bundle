<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Tests\Service;

use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service\CourseApi;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service\CourseProvider;
use Dbp\Relay\BasePersonBundle\Service\DummyPersonProvider;
use Dbp\Relay\CoreBundle\Exception\ApiError;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

class CourseProviderTest extends TestCase
{
    /** @var CourseProvider */
    private $api;
    private $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new CourseApi();
        $this->service->setConfig(['org_root_id' => '1']); // some value is required
        $this->api = new CourseProvider($this->service, new EventDispatcher(), new DummyPersonProvider());
        $this->mockResponses([]);
    }

    private function mockResponses(array $responses)
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $this->service->setClientHandler($stack);
    }

    public function testGetCourses()
    {
        $this->mockResponses([
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'], file_get_contents(__DIR__.'/courses_by_organization_response.xml')),
        ]);

        $courses = $this->api->getCourses();
        $this->assertSame(34, count($courses));
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
        $this->api->getCourses();
    }

    public function testGetCoursesInvalidXML()
    {
        $this->mockResponses([
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'], file_get_contents(__DIR__.'/course_response_invalid_xml.xml')),
        ]);

        $this->expectException(ApiError::class);
        $this->api->getCourses();
    }

    public function testGetCourseById()
    {
        $this->mockResponses([
            // new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'], file_get_contents(__DIR__.'/RoomsResponse.xml')),
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'], file_get_contents(__DIR__.'/course_by_id_response.xml')),
        ]);

        $course = $this->api->getCourseById('240759');

        $this->assertSame('240759', $course->getIdentifier());
        $this->assertSame('Computational Intelligence', $course->getName());
        $this->assertSame('UE', $course->getType());
        //$this->assertSame('Anwendungen der wichtigsten Methoden aus den Bereichen Maschinelles Lernen und Neuronale Netzwerke. Praxis-orientierte Probleme des Maschinellen Lernens im Allgemeinen und der einzelnen Ansätze im speziellen werden aufgezeigt und die entsprechende Lösungsansätze präsentiert.', $course->getDescription());
    }

    public function testGetCourseByIdNotFound()
    {
        $this->mockResponses([
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'], file_get_contents(__DIR__.'/course_by_id_response.xml')),
        ]);

        $course = $this->api->getCourseById('123');
        $this->assertNull($course);
    }

    public function testGetCourseById500()
    {
        $this->mockResponses([
            new Response(500, ['Content-Type' => 'text/xml;charset=utf-8'], ''),
        ]);

        $this->expectException(ApiError::class);
        $this->api->getCourseById('123');
    }

    public function testGetCoursesByOrganization()
    {
        $this->mockResponses([
            new Response(200, ['Content-Type' => 'text/xml;charset=utf-8'], file_get_contents(__DIR__.'/courses_by_organization_response.xml')),
        ]);

        $courses = $this->api->getCoursesByOrganization('abc');
        $this->assertSame(34, count($courses));

        $course = $courses[0];
        $this->assertSame('241333', $course->getIdentifier());
        $this->assertSame('Technische Informatik 1', $course->getName());
        $this->assertSame('VO', $course->getType());
    }
}
