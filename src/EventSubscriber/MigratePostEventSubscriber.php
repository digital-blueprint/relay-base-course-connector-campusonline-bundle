<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\EventSubscriber;

use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service\CourseProvider;
use Dbp\Relay\CoreBundle\DB\MigratePostEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

readonly class MigratePostEventSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            MigratePostEvent::class => 'onMigratePostEvent',
        ];
    }

    public function __construct(
        private CourseProvider $courseProvider)
    {
    }

    public function onMigratePostEvent(MigratePostEvent $event): void
    {
        $output = $event->getOutput();
        try {
            // only recreate cache if it is empty (initially or after schema change)
            if (empty($this->courseProvider->getCourses(1, 1))) {
                $output->writeln('Initializing base course cache...');
                $this->courseProvider->recreateCoursesCache();
            }
        } catch (\Throwable $throwable) {
            $output->writeln('Error initializing base course cache: '.$throwable->getMessage());
        }
    }
}
