<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Table(name: 'course_titles')]
#[ORM\Entity]
class CachedCourseTitle
{
    public const COURSE_UID_COLUMN_NAME = 'courseUid';
    public const LANGUAGE_TAG_COLUMN_NAME = 'languageTag';
    public const TITLE_COLUMN_NAME = 'title';

    public const ALL_COLUMN_NAMES = [
        self::COURSE_UID_COLUMN_NAME,
        self::LANGUAGE_TAG_COLUMN_NAME,
        self::TITLE_COLUMN_NAME,
    ];

    #[ORM\Id]
    #[ORM\JoinColumn(name: self::COURSE_UID_COLUMN_NAME, referencedColumnName: 'uid', onDelete: 'CASCADE')]
    #[ORM\ManyToOne(targetEntity: CachedCourse::class, inversedBy: 'titles')]
    private ?CachedCourse $course = null;

    #[ORM\Id]
    #[ORM\Column(name: self::LANGUAGE_TAG_COLUMN_NAME, type: 'string', length: 2)]
    private ?string $languageTag = null;

    #[ORM\Column(name: self::TITLE_COLUMN_NAME, type: 'string', length: 255)]
    private ?string $title = null;

    public function getCourse(): ?CachedCourse
    {
        return $this->course;
    }

    public function setCourseUid(?CachedCourse $course): void
    {
        $this->course = $course;
    }

    public function getLanguageTag(): ?string
    {
        return $this->languageTag;
    }

    public function setLanguageTag(?string $languageTag): void
    {
        $this->languageTag = $languageTag;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): void
    {
        $this->title = $title;
    }
}
