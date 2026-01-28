<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;

final class Version20260128091200 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'recreate cache tables because of schema changes';
    }

    public function up(Schema $schema): void
    {
        $this->recreateCacheTables();
    }
}
