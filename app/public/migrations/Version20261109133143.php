<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261109133143 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Traffic log: exchanges with their tool calls; usage records keep their request id ending';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE exchange (id BIGINT AUTO_INCREMENT NOT NULL, occurred_at DATETIME NOT NULL, request_tail VARCHAR(8) NOT NULL, log_file VARCHAR(190) NOT NULL, endpoint VARCHAR(64) NOT NULL, provider VARCHAR(32) NOT NULL, model VARCHAR(128) NOT NULL, client VARCHAR(64) NOT NULL, session_id VARCHAR(64) NOT NULL, auth_index VARCHAR(64) NOT NULL, account VARCHAR(190) NOT NULL, stream TINYINT NOT NULL, status_code INT NOT NULL, failed TINYINT NOT NULL, error LONGTEXT NOT NULL, stop_reason VARCHAR(64) NOT NULL, duration_ms INT NOT NULL, input_tokens INT NOT NULL, output_tokens INT NOT NULL, cache_read_tokens INT NOT NULL, cache_creation_tokens INT NOT NULL, reasoning_tokens INT NOT NULL, prompt_kind VARCHAR(16) NOT NULL, prompt LONGTEXT NOT NULL, thinking LONGTEXT NOT NULL, answer LONGTEXT NOT NULL, message_count INT NOT NULL, system_chars INT NOT NULL, tools_declared INT NOT NULL, tool_call_count INT NOT NULL, tool_error_count INT NOT NULL, request_bytes INT NOT NULL, response_bytes INT NOT NULL, archive_path VARCHAR(255) NOT NULL, UNIQUE INDEX UNIQ_D33BB0799DF1D865 (log_file), INDEX exchange_occurred (occurred_at), INDEX exchange_session (session_id, occurred_at), INDEX exchange_request (request_tail), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE tool_call (id BIGINT AUTO_INCREMENT NOT NULL, is_error TINYINT DEFAULT NULL, answered TINYINT NOT NULL, output LONGTEXT NOT NULL, answered_at DATETIME DEFAULT NULL, call_id VARCHAR(128) NOT NULL, name VARCHAR(128) NOT NULL, input LONGTEXT NOT NULL, exchange_id BIGINT NOT NULL, INDEX tool_call_id (call_id), INDEX tool_name (name), INDEX IDX_17CC6F7568AFD1A0 (exchange_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE tool_call ADD CONSTRAINT FK_17CC6F7568AFD1A0 FOREIGN KEY (exchange_id) REFERENCES exchange (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE usage_record ADD request_tail VARCHAR(8) DEFAULT \'\' NOT NULL');
        $this->addSql('CREATE INDEX usage_request ON usage_record (request_tail)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tool_call DROP FOREIGN KEY FK_17CC6F7568AFD1A0');
        $this->addSql('DROP TABLE exchange');
        $this->addSql('DROP TABLE tool_call');
        $this->addSql('DROP INDEX usage_request ON usage_record');
        $this->addSql('ALTER TABLE usage_record DROP request_tail');
    }
}
