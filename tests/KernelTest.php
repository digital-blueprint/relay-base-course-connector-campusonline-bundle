<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Tests;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;

class KernelTest extends ApiTestCase
{
    public function testBasics()
    {
        $client = static::createClient();
        $this->assertNotNull($client);
    }
}
