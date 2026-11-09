<?php

namespace App\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;

/** A fresh SQLite schema from the entities for each test, and no usage spool of an earlier one (the collector replays it). */
trait Database
{
    private static function resetDatabase(EntityManagerInterface $entityManager): void
    {
        $metadata = $entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool = new SchemaTool($entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
        if (is_dir('/tmp/cliproxy-test/usage')) {
            exec('chmod -R u+rwX /tmp/cliproxy-test/usage; rm -rf /tmp/cliproxy-test/usage');
        }
    }
}
