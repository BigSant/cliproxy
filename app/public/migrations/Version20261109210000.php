<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109210000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'alert.message_key and alert.message_params: an alert shown in the page\'s language (LT | EN); open alerts get them on the collector\'s next run, earlier ones keep their Lithuanian message';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alert ADD message_key VARCHAR(190) DEFAULT NULL, ADD message_params JSON DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE alert DROP message_key, DROP message_params');
    }
}
