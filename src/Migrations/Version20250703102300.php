<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Migrations;

use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Entity\CachedCourse;
use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Entity\CachedCourseTitle;
use Doctrine\DBAL\Schema\Schema;

final class Version20250703102300 extends EntityManagerMigration
{
    public function getDescription(): string
    {
        return 'creates the courses and the course_titles table';
    }

    public function up(Schema $schema): void
    {
        $uidColumn = CachedCourse::UID_COLUMN_NAME;
        $courseCodeColumn = CachedCourse::COURSE_CODE_COLUMN_NAME;
        $semesterKeyColumn = CachedCourse::SEMESTER_KEY_COLUMN_NAME;
        $courseTypeColumn = CachedCourse::COURSE_TYPE_KEY_COLUMN_NAME;

        $createStatement = "CREATE TABLE courses ($uidColumn VARCHAR(32) NOT NULL, $courseCodeColumn VARCHAR(32), $semesterKeyColumn VARCHAR(5), $courseTypeColumn VARCHAR(8), PRIMARY KEY($uidColumn))";
        $this->addSql($createStatement);

        $courseUidColumn = CachedCourseTitle::COURSE_UID_COLUMN_NAME;
        $languageTagColumn = CachedCourseTitle::LANGUAGE_TAG_COLUMN_NAME;
        $titleColumn = CachedCourseTitle::TITLE_COLUMN_NAME;

        $createStatement = "CREATE TABLE course_titles ($courseUidColumn VARCHAR(32) NOT NULL, $languageTagColumn VARCHAR(2) NOT NULL, $titleColumn VARCHAR(255) NOT NULL, PRIMARY KEY($courseUidColumn, $languageTagColumn), FOREIGN KEY($courseUidColumn) REFERENCES courses($uidColumn) ON DELETE CASCADE)";
        $this->addSql($createStatement);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE courses');
        $this->addSql('DROP TABLE course_titles');
    }
}
