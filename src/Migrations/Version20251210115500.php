<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

final class Version20251210115500 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'creates the courses_staging and the course_titles_staging table';
    }

    public function up(Schema $schema): void
    {
        $this->recreateCacheTables();
    }
}
