<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261109144934 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Usage records kept whole (raw, request id, key); exchange kind and has_thinking, full-text index; alerts';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE alert (last_seen_at DATETIME NOT NULL, resolved_at DATETIME DEFAULT NULL, notified_at DATETIME DEFAULT NULL, id VARCHAR(190) NOT NULL, level VARCHAR(16) NOT NULL, message VARCHAR(500) NOT NULL, first_seen_at DATETIME NOT NULL, INDEX alert_open (resolved_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE exchange ADD kind VARCHAR(16) DEFAULT \'main\' NOT NULL, ADD has_thinking TINYINT DEFAULT 0 NOT NULL');
        $this->addSql("UPDATE exchange SET has_thinking = 1 WHERE thinking <> ''");
        $this->addSql('CREATE FULLTEXT INDEX exchange_fulltext ON exchange (prompt, answer, thinking, error)');
        $this->addSql('ALTER TABLE usage_record ADD record_key VARCHAR(40) DEFAULT NULL, ADD request_id VARCHAR(64) DEFAULT \'\' NOT NULL, ADD ttft_ms INT DEFAULT 0 NOT NULL, ADD user_agent VARCHAR(190) DEFAULT \'\' NOT NULL, ADD reasoning_effort VARCHAR(32) DEFAULT \'\' NOT NULL, ADD parent_session_id VARCHAR(64) DEFAULT \'\' NOT NULL, ADD node_kind VARCHAR(32) DEFAULT \'\' NOT NULL, ADD is_compaction TINYINT DEFAULT 0 NOT NULL, ADD raw JSON DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX usage_record_key ON usage_record (record_key)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE alert');
        $this->addSql('DROP INDEX exchange_fulltext ON exchange');
        $this->addSql('ALTER TABLE exchange DROP kind, DROP has_thinking');
        $this->addSql('DROP INDEX usage_record_key ON usage_record');
        $this->addSql('ALTER TABLE usage_record DROP record_key, DROP request_id, DROP ttft_ms, DROP user_agent, DROP reasoning_effort, DROP parent_session_id, DROP node_kind, DROP is_compaction, DROP raw');
    }
}
