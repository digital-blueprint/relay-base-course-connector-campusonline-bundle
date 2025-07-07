<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ORM\Table(name: 'courses')]
#[ORM\Entity]
class CachedCourse
{
    public const UID_COLUMN = 'uid';
    public const COURSE_CODE_COLUMN = 'courseCode';
    public const SEMESTER_KEY_COLUMN = 'semesterKey';
    public const COURSE_TYPE_KEY_COLUMN = 'courseTypeKey';

    #[ORM\Id]
    #[ORM\Column(name: self::UID_COLUMN, type: 'string', length: 32)]
    private ?string $uid = null;
    #[ORM\Column(name: self::COURSE_CODE_COLUMN, type: 'string', length: 32)]
    private ?string $courseCode = null;
    #[ORM\Column(name: self::SEMESTER_KEY_COLUMN, type: 'string', length: 5)]
    private ?string $semesterKey = null;
    #[ORM\Column(name: self::COURSE_TYPE_KEY_COLUMN, type: 'string', length: 8)]
    private ?string $courseTypeKey = null;

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

}
