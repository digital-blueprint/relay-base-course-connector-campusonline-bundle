<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Cron;

use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\DependencyInjection\Configuration;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service\CourseProvider;
use Dbp\Relay\CoreBundle\Cron\CronJobInterface;
use Dbp\Relay\CoreBundle\Cron\CronOptions;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

class CacheRefreshCronJob implements CronJobInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const DEFAULT_INTERVAL = '15 3 * * *'; // Daily at 3:15 AM

    private string $interval = self::DEFAULT_INTERVAL;

    public function __construct(
        private readonly CourseProvider $courseProvider)
    {
    }

    public function setConfig(array $config): void
    {
        $this->interval = $config[Configuration::CACHE_REFRESH_INTERVAL_NODE] ?? self::DEFAULT_INTERVAL;
    }

    public function getName(): string
    {
        return 'BaseRoom Cache Refresh';
    }

    public function getInterval(): string
    {
        return $this->interval;
    }

    public function run(CronOptions $options): void
    {
        try {
            $this->courseProvider->recreateCoursesCache();
        } catch (\Throwable $throwable) {
            $this->logger->error('Error refreshing base course cache: '.$throwable->getMessage(), [
                'exception' => $throwable,
            ]);
        }
    }
}
