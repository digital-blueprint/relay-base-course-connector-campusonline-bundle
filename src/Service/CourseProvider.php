<?php

declare(strict_types=1);

namespace Dbp\Relay\CourseConnectorCampusonlineBundle\Service;

use Dbp\Relay\CourseBundle\API\CourseProviderInterface;
use Dbp\Relay\CourseBundle\Entity\Course;

class CourseProvider implements CourseProviderInterface
{
    public function getCourseById(string $identifier, array $options = []): ?Course
    {
        return null;
    }

    public function getCourses(array $options = []): array
    {
        return [];
    }

    public function getCoursesByOrganization(string $orgUnitId, array $options = []): array
    {
        return [];
    }

    public function getCoursesByPerson(string $personId, array $options = []): array
    {
        return [];
    }

    /*
     * @return CourseAttendee[]
     */
    public function getStudentsByCourse(string $courseId, array $options = []): array
    {
        return [];
    }

    public function getExamsByCourse(string $courseId, array $options = []): array
    {
        return [];
    }
}
