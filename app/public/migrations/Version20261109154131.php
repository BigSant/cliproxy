<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261109154131 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Alert episodes, exchange to usage record link, usage retries, client keys masked in raw';
    }

    public function up(Schema $schema): void
    {
        // Alerts become episodes: the key moves to alert_key (rows kept), id becomes a number.
        $this->addSql("ALTER TABLE alert ADD alert_key VARCHAR(190) NOT NULL DEFAULT ''");
        $this->addSql('UPDATE alert SET alert_key = id');
        $this->addSql('ALTER TABLE alert DROP PRIMARY KEY, DROP COLUMN id, ADD id INT AUTO_INCREMENT NOT NULL FIRST, ADD PRIMARY KEY (id)');
        $this->addSql('ALTER TABLE alert ALTER COLUMN alert_key DROP DEFAULT');
        $this->addSql('CREATE INDEX alert_key ON alert (alert_key)');
        $this->addSql('ALTER TABLE exchange ADD usage_record_id BIGINT DEFAULT NULL');
        $this->addSql('CREATE INDEX exchange_usage ON exchange (usage_record_id)');
        $this->addSql('ALTER TABLE usage_record ADD is_retry TINYINT DEFAULT 0 NOT NULL');
        // The client key was stored whole in raw: only its last four characters stay (UsageRecord::sanitize).
        $this->addSql("UPDATE usage_record SET raw = JSON_SET(raw, '$.api_key', CONCAT('…', RIGHT(JSON_UNQUOTE(JSON_EXTRACT(raw, '$.api_key')), 4)))"
            ." WHERE JSON_EXTRACT(raw, '$.api_key') IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(raw, '$.api_key')) NOT LIKE '…%'");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP INDEX alert_key ON alert');
        $this->addSql('ALTER TABLE alert DROP alert_key, CHANGE id id VARCHAR(190) NOT NULL');
        $this->addSql('DROP INDEX exchange_usage ON exchange');
        $this->addSql('ALTER TABLE exchange DROP usage_record_id');
        $this->addSql('ALTER TABLE usage_record DROP is_retry');
    }
}
