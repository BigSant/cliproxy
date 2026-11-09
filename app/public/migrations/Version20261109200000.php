<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261109200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'exchange.reasoning_effort, filled from the linked usage records; the journal rows of client probes (HEAD /api/hello) go, their files stay in the archive';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE exchange ADD reasoning_effort VARCHAR(32) DEFAULT \'\' NOT NULL');
        $this->addSql("UPDATE exchange e JOIN usage_record u ON u.id = e.usage_record_id SET e.reasoning_effort = u.reasoning_effort WHERE e.usage_record_id <> '0'");
        $this->addSql("DELETE FROM tool_call WHERE exchange_id IN (SELECT id FROM (SELECT id FROM exchange WHERE endpoint = '/api/hello') probes)");
        $this->addSql("DELETE FROM exchange WHERE endpoint = '/api/hello'");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE exchange DROP reasoning_effort');
    }
}
