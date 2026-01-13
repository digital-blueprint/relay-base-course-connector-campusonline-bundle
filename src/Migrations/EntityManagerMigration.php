<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Migrations;

use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\DependencyInjection\DbpRelayBaseCourseConnectorCampusonlineExtension;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Entity\CachedCourse;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Entity\CachedCourseTitle;
use Dbp\Relay\CoreBundle\Doctrine\AbstractEntityManagerMigration;

abstract class EntityManagerMigration extends AbstractEntityManagerMigration
{
    protected function getEntityManagerId(): string
    {
        return DbpRelayBaseCourseConnectorCampusonlineExtension::ENTITY_MANAGER_ID;
    }

    protected function recreateCacheTables(): void
    {
        $coursesTableName = CachedCourse::TABLE_NAME;
        $courseTitlesTableName = CachedCourseTitle::TABLE_NAME;
        $coursesStagingTableName = CachedCourse::STAGING_TABLE_NAME;
        $courseTitlesStagingTableName = CachedCourseTitle::STAGING_TABLE_NAME;

        $this->addSql("DROP TABLE IF EXISTS $courseTitlesStagingTableName");
        $this->addSql("DROP TABLE IF EXISTS $courseTitlesTableName");
        $this->addSql("DROP TABLE IF EXISTS $coursesStagingTableName");
        $this->addSql("DROP TABLE IF EXISTS $coursesTableName");

        $uidColumn = CachedCourse::UID_COLUMN_NAME;
        $courseCodeColumn = CachedCourse::COURSE_CODE_COLUMN_NAME;
        $semesterKeyColumn = CachedCourse::SEMESTER_KEY_COLUMN_NAME;
        $courseTypeColumn = CachedCourse::COURSE_TYPE_KEY_COLUMN_NAME;
        $courseIdentityCodeUidColumn = CachedCourse::COURSE_IDENTITY_CODE_UID_COLUMN_NAME;

        $createStatement = <<<STMT
               CREATE TABLE $coursesTableName (
                   $uidColumn VARCHAR(32) NOT NULL,
                   $courseCodeColumn VARCHAR(32) DEFAULT NULL,
                   $semesterKeyColumn VARCHAR(5) DEFAULT NULL,
                   $courseTypeColumn VARCHAR(8) DEFAULT NULL,
                   $courseIdentityCodeUidColumn VARCHAR(16) DEFAULT NULL,
                   PRIMARY KEY($uidColumn)
               ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB;
            STMT;
        $this->addSql($createStatement);

        $courseUidColumn = CachedCourseTitle::COURSE_UID_COLUMN_NAME;
        $languageTagColumn = CachedCourseTitle::LANGUAGE_TAG_COLUMN_NAME;
        $titleColumn = CachedCourseTitle::TITLE_COLUMN_NAME;

        $createStatement = <<<STMT
               CREATE TABLE $courseTitlesTableName (
                   $courseUidColumn VARCHAR(32) NOT NULL,
                   $languageTagColumn VARCHAR(2) NOT NULL, $titleColumn VARCHAR(255) NOT NULL,
                   PRIMARY KEY($courseUidColumn, $languageTagColumn)
               ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            STMT;
        $this->addSql($createStatement);

        $this->addSql("CREATE TABLE $coursesStagingTableName LIKE $coursesTableName");
        $this->addSql("CREATE TABLE $courseTitlesStagingTableName LIKE $courseTitlesTableName");
    }
}
