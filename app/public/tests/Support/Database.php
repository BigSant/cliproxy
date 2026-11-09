<?php

namespace App\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/** A fresh SQLite schema from the entities for each test. */
trait Database
{
    private static function resetDatabase(EntityManagerInterface $entityManager): void
    {
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }
}
