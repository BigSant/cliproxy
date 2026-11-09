<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261109135910 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Usage records split cache writes into 5-minute and one-hour ones';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE usage_record ADD cache_creation5m_tokens INT DEFAULT 0 NOT NULL, ADD cache_creation1h_tokens INT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE usage_record DROP cache_creation5m_tokens, DROP cache_creation1h_tokens');
    }
}
