<?php

declare(strict_types=1);

namespace Dbp\Relay\BaseCourseConnectorCampusonlineBundle\Migrations;

use Dbp\Relay\BaseCourseConnectorCampusonlineBundle\DependencyInjection\DbpRelayBaseCourseConnectorCampusonlineExtension;
use Dbp\Relay\CoreBundle\Doctrine\AbstractEntityManagerMigration;

abstract class EntityManagerMigration extends AbstractEntityManagerMigration
{
    protected function getEntityManagerId(): string
    {
        return DbpRelayBaseCourseConnectorCampusonlineExtension::ENTITY_MANAGER_ID;
    }
}
