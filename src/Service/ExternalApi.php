<?php

declare(strict_types=1);

namespace Dbp\Relay\CourseConnectorCampusonlineBundle\Service;

use Dbp\Relay\CourseConnectorCampusonlineBundle\Entity\Removeme;

class ExternalApi implements RemovemeProviderInterface
{
    private $removemes;

    public function __construct(MyCustomService $service)
    {
        // Make phpstan happy
        $service = $service;

        $this->removemes = [];
        $removeme1 = new Removeme();
        $removeme1->setIdentifier('graz');
        $removeme1->setName('Graz');

        $removeme2 = new Removeme();
        $removeme2->setIdentifier('vienna');
        $removeme2->setName('Vienna');

        $this->removemes[] = $removeme1;
        $this->removemes[] = $removeme2;
    }

    public function getRemovemeById(string $identifier): ?Removeme
    {
        foreach ($this->removemes as $removeme) {
            if ($removeme->getIdentifier() === $identifier) {
                return $removeme;
            }
        }

        return null;
    }

    public function getRemovemes(): array
    {
        return $this->removemes;
    }
}
