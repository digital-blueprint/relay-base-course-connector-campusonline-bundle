<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: self::TABLE_NAME)]
#[ORM\Entity]
class CachedCourse
{
    public const TABLE_NAME = 'courses';
    public const STAGING_TABLE_NAME = 'courses_staging';

    public const UID_COLUMN_NAME = 'uid';
    public const COURSE_CODE_COLUMN_NAME = 'courseCode';
    public const SEMESTER_KEY_COLUMN_NAME = 'semesterKey';
    public const COURSE_TYPE_KEY_COLUMN_NAME = 'courseTypeKey';
    public const LECTURERS_COLUMN_NAME = 'lecturers';

    public const ALL_COLUMN_NAMES = [
        self::UID_COLUMN_NAME,
        self::COURSE_CODE_COLUMN_NAME,
        self::SEMESTER_KEY_COLUMN_NAME,
        self::COURSE_TYPE_KEY_COLUMN_NAME,
        self::LECTURERS_COLUMN_NAME,
    ];

    #[ORM\Id]
    #[ORM\Column(name: self::UID_COLUMN_NAME, type: 'string', length: 32)]
    private ?string $uid = null;
    #[ORM\Column(name: self::COURSE_CODE_COLUMN_NAME, type: 'string', length: 32)]
    private ?string $courseCode = null;
    #[ORM\Column(name: self::SEMESTER_KEY_COLUMN_NAME, type: 'string', length: 5)]
    private ?string $semesterKey = null;
    #[ORM\Column(name: self::COURSE_TYPE_KEY_COLUMN_NAME, type: 'string', length: 8)]
    private ?string $courseTypeKey = null;

    /**
     * @var string[]|null
     */
    #[ORM\Column(name: self::LECTURERS_COLUMN_NAME, type: 'json', nullable: true)]
    private ?array $lecturers = null;

    #[ORM\OneToMany(targetEntity: CachedCourseTitle::class, mappedBy: 'course')]
    private Collection $titles;

    public function __construct()
    {
        $this->titles = new ArrayCollection();
    }

    public function getUid(): ?string
    {
        return $this->uid;
    }

    public function setUid(?string $uid): void
    {
        $this->uid = $uid;
    }

    public function getCourseCode(): ?string
    {
        return $this->courseCode;
    }

    public function setCourseCode(?string $courseCode): void
    {
        $this->courseCode = $courseCode;
    }

    public function getSemesterKey(): ?string
    {
        return $this->semesterKey;
    }

    public function setSemesterKey(?string $semesterKey): void
    {
        $this->semesterKey = $semesterKey;
    }

    public function getCourseTypeKey(): ?string
    {
        return $this->courseTypeKey;
    }

    public function setCourseTypeKey(?string $courseTypeKey): void
    {
        $this->courseTypeKey = $courseTypeKey;
    }

    public function getTitles(): Collection
    {
        return $this->titles;
    }

    public function setTitles(Collection $titles): void
    {
        $this->titles = $titles;
    }

    /**
     * @return string[]|null
     */
    public function getLecturers(): ?array
    {
        return $this->lecturers;
    }

    /**
     * @param string[]|null $lecturers
     */
    public function setLecturers(?array $lecturers): void
    {
        $this->lecturers = $lecturers;
    }
}
