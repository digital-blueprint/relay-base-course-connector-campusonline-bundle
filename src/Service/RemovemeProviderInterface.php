<?php

declare(strict_types=1);

namespace Dbp\Relay\CourseConnectorCampusonlineBundle\Service;

use Dbp\Relay\CourseConnectorCampusonlineBundle\Entity\Removeme;

interface RemovemeProviderInterface
{
    public function getRemovemeById(string $identifier): ?Removeme;

    public function getRemovemes(): array;
}
