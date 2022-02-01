<?php

declare(strict_types=1);

namespace Dbp\Relay\CourseConnectorCampusonlineBundle\Controller;

use Dbp\Relay\CourseConnectorCampusonlineBundle\Entity\Removeme;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;

class LoggedInOnly extends AbstractController
{
    public function __invoke(Removeme $data, Request $request): Removeme
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        return $data;
    }
}
