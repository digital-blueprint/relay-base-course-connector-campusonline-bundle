<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Migrations;

use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Entity\CachedCourse;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Entity\CachedCourseTitle;
use Doctrine\DBAL\Schema\Schema;

final class Version20251210115500 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'creates the courses_staging and the course_titles_staging table';
    }

    public function up(Schema $schema): void
    {
        $coursesTableName = CachedCourse::TABLE_NAME;
        $coursesStagingTableName = CachedCourse::STAGING_TABLE_NAME;

        $this->addSql("CREATE TABLE $coursesStagingTableName LIKE $coursesTableName");

        $courseTitlesTableName = CachedCourseTitle::TABLE_NAME;
        $courseTitlesStagingTableName = CachedCourseTitle::STAGING_TABLE_NAME;

        $this->addSql("CREATE TABLE $courseTitlesStagingTableName LIKE $courseTitlesTableName");
    }
}
