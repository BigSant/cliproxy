<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261109171922 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'exchange.occurred_micros (a WebSocket turn told from the next one to the microsecond), index usage_record.request_id (retries)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE exchange ADD occurred_micros INT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE INDEX usage_request_id ON usage_record (request_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE exchange DROP occurred_micros');
        $this->addSql('DROP INDEX usage_request_id ON usage_record');
    }
}
