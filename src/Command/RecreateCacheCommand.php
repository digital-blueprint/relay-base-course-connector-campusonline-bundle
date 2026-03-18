<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Command;

use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Service\CourseProvider;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'dbp:relay:base-course-connector-campusonline:recreate-cache',
    description: 'Re-create the course cache',
)]
class RecreateCacheCommand extends Command
{
    public function __construct(private readonly CourseProvider $courseProvider)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $return = Command::SUCCESS;
        try {
            $this->courseProvider->recreateCoursesCache();
        } catch (\Throwable $exception) {
            $output->writeln('failed to re-create course cache: '.$exception->getMessage());
            $return = Command::FAILURE;
        }

        return $return;
    }
}
