<?php

declare(strict_types=1);

namespace Dbp\Relay\CourseConnectorCampusonlineBundle\Entity;

use ApiPlatform\Core\Annotation\ApiProperty;
use ApiPlatform\Core\Annotation\ApiResource;
use Dbp\Relay\CourseConnectorCampusonlineBundle\Controller\LoggedInOnly;
use Symfony\Component\Serializer\Annotation\Groups;

/**
 * @ApiResource(
 *     collectionOperations={
 *         "get" = {
 *             "path" = "/course-connector-campusonline/removemes",
 *             "openapi_context" = {
 *                 "tags" = {"Dummy"},
 *             },
 *         }
 *     },
 *     itemOperations={
 *         "get" = {
 *             "path" = "/course-connector-campusonline/removemes/{identifier}",
 *             "openapi_context" = {
 *                 "tags" = {"Dummy"},
 *             },
 *         },
 *         "put" = {
 *             "path" = "/course-connector-campusonline/removemes/{identifier}",
 *             "openapi_context" = {
 *                 "tags" = {"Dummy"},
 *             },
 *         },
 *         "delete" = {
 *             "path" = "/course-connector-campusonline/removemes/{identifier}",
 *             "openapi_context" = {
 *                 "tags" = {"Dummy"},
 *             },
 *         },
 *         "loggedin_only" = {
 *             "security" = "is_granted('IS_AUTHENTICATED_FULLY')",
 *             "method" = "GET",
 *             "path" = "/course-connector-campusonline/removemes/{identifier}/loggedin-only",
 *             "controller" = LoggedInOnly::class,
 *             "openapi_context" = {
 *                 "summary" = "Only works when logged in.",
 *                 "tags" = {"Dummy"},
 *             },
 *         }
 *     },
 *     iri="https://schema.org/Removeme",
 *     shortName="CourseConnectorCampusonlineRemoveme",
 *     normalizationContext={
 *         "groups" = {"CourseConnectorCampusonlineRemoveme:output"},
 *         "jsonld_embed_context" = true
 *     },
 *     denormalizationContext={
 *         "groups" = {"CourseConnectorCampusonlineRemoveme:input"},
 *         "jsonld_embed_context" = true
 *     }
 * )
 */
class Removeme
{
    /**
     * @ApiProperty(identifier=true)
     */
    private $identifier;

    /**
     * @ApiProperty(iri="https://schema.org/name")
     * @Groups({"CourseConnectorCampusonlineRemoveme:output", "CourseConnectorCampusonlineRemoveme:input"})
     *
     * @var string
     */
    private $name;

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    public function setIdentifier(string $identifier): void
    {
        $this->identifier = $identifier;
    }
}
