<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service;

use Dbp\Relay\CoreBundle\HealthCheck\CheckInterface;
use Dbp\Relay\CoreBundle\HealthCheck\CheckOptions;
use Dbp\Relay\CoreBundle\HealthCheck\CheckResult;

class HealthCheck implements CheckInterface
{
    /**
     * @var CourseApi
     */
    private $api;

    public function __construct(CourseApi $api)
    {
        $this->api = $api;
    }

    public function getName(): string
    {
        return 'base-course-connector-campusonline';
    }

    public function check(CheckOptions $options): array
    {
        $result = new CheckResult('Check if the CO API works');

        $result->set(CheckResult::STATUS_SUCCESS);
        try {
            $this->api->checkConnection();
        } catch (\Throwable $e) {
            $result->set(CheckResult::STATUS_FAILURE, $e->getMessage(), ['exception' => $e]);
        }

        return [$result];
    }
}
