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
        $coursesTableName = CachedCourse::TABLE_NAME;
        $uidColumn = CachedCourse::UID_COLUMN_NAME;
        $courseCodeColumn = CachedCourse::COURSE_CODE_COLUMN_NAME;
        $semesterKeyColumn = CachedCourse::SEMESTER_KEY_COLUMN_NAME;
        $courseTypeColumn = CachedCourse::COURSE_TYPE_KEY_COLUMN_NAME;
        $lecturersColumn = CachedCourse::LECTURERS_COLUMN_NAME;

        $createStatement = "CREATE TABLE $coursesTableName ($uidColumn VARCHAR(32) NOT NULL, $courseCodeColumn VARCHAR(32), $semesterKeyColumn VARCHAR(5), $courseTypeColumn VARCHAR(8), $lecturersColumn JSON DEFAULT NULL, PRIMARY KEY($uidColumn))";
        $this->addSql($createStatement);

        $courseTitlesTableName = CachedCourseTitle::TABLE_NAME;
        $courseUidColumn = CachedCourseTitle::COURSE_UID_COLUMN_NAME;
        $languageTagColumn = CachedCourseTitle::LANGUAGE_TAG_COLUMN_NAME;
        $titleColumn = CachedCourseTitle::TITLE_COLUMN_NAME;

        $createStatement = "CREATE TABLE $courseTitlesTableName ($courseUidColumn VARCHAR(32) NOT NULL, $languageTagColumn VARCHAR(2) NOT NULL, $titleColumn VARCHAR(255) NOT NULL, PRIMARY KEY($courseUidColumn, $languageTagColumn), FOREIGN KEY($courseUidColumn) REFERENCES $coursesTableName($uidColumn) ON DELETE CASCADE)";
        $this->addSql($createStatement);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE courses');
        $this->addSql('DROP TABLE course_titles');
    }
}
