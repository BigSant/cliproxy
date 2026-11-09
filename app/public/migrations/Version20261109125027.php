<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261109125027 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'The hub: usage records, account limit snapshots, dashboard settings';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE quota_snapshot (id INT AUTO_INCREMENT NOT NULL, windows JSON NOT NULL, auth_index VARCHAR(64) NOT NULL, account VARCHAR(190) NOT NULL, provider VARCHAR(32) NOT NULL, fetched_at DATETIME NOT NULL, source VARCHAR(16) NOT NULL, plan VARCHAR(64) DEFAULT NULL, banked_resets INT DEFAULT NULL, error VARCHAR(255) DEFAULT NULL, INDEX quota_account (auth_index, fetched_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE setting (name VARCHAR(64) NOT NULL, value JSON NOT NULL, PRIMARY KEY (name)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE usage_record (id BIGINT AUTO_INCREMENT NOT NULL, occurred_at DATETIME NOT NULL, provider VARCHAR(32) NOT NULL, model VARCHAR(128) NOT NULL, auth_index VARCHAR(64) NOT NULL, account VARCHAR(190) NOT NULL, session_id VARCHAR(64) NOT NULL, client_key VARCHAR(8) NOT NULL, endpoint VARCHAR(64) NOT NULL, input_tokens INT NOT NULL, output_tokens INT NOT NULL, reasoning_tokens INT NOT NULL, cache_read_tokens INT NOT NULL, cache_creation_tokens INT NOT NULL, total_tokens INT NOT NULL, latency_ms INT NOT NULL, failed TINYINT NOT NULL, status_code INT NOT NULL, INDEX usage_occurred (occurred_at), INDEX usage_account (auth_index, occurred_at), INDEX usage_session (session_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE quota_snapshot');
        $this->addSql('DROP TABLE setting');
        $this->addSql('DROP TABLE usage_record');
    }
}
