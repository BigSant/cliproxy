<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261109190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Data only: usage_record.occurred_micros from raw.timestamp; Codex input tokens without the cached ones (usage records and the exchanges that took them)';
    }

    public function up(Schema $schema): void
    {
        $this->skipIf(!$this->connection->getDatabasePlatform() instanceof AbstractMySQLPlatform, 'MySQL only');
        // The fraction of the original timestamp, to the microsecond (rows stored before the column existed).
        $this->addSql(<<<'SQL'
            UPDATE usage_record
            SET occurred_micros = CAST(RPAD(SUBSTRING(REGEXP_SUBSTR(JSON_UNQUOTE(JSON_EXTRACT(raw, '$.timestamp')), '\\.[0-9]+'), 2, 6), 6, '0') AS UNSIGNED)
            WHERE occurred_micros = 0 AND raw IS NOT NULL
              AND REGEXP_SUBSTR(JSON_UNQUOTE(JSON_EXTRACT(raw, '$.timestamp')), '\\.[0-9]+') IS NOT NULL
            SQL);
        // Exchanges whose tokens came from a Codex record (the log's own are without the cached ones already) first,
        // while the record still has the old count.
        $this->addSql(<<<'SQL'
            UPDATE exchange e JOIN usage_record u ON u.id = e.usage_record_id
            SET e.input_tokens = GREATEST(e.input_tokens - u.cache_read_tokens, 0)
            WHERE u.provider IN ('codex', 'openai') AND u.cache_read_tokens > 0
              AND e.input_tokens = u.input_tokens AND e.cache_read_tokens = u.cache_read_tokens
            SQL);
        $this->addSql(<<<'SQL'
            UPDATE usage_record
            SET input_tokens = CASE
                WHEN JSON_TYPE(JSON_EXTRACT(raw, '$.token_breakdown.input.uncached_tokens')) IN ('INTEGER', 'UNSIGNED INTEGER')
                    THEN JSON_EXTRACT(raw, '$.token_breakdown.input.uncached_tokens')
                ELSE GREATEST(input_tokens - cache_read_tokens, 0) END
            WHERE provider IN ('codex', 'openai') AND cache_read_tokens > 0
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->throwIrreversibleMigrationException('Data only');
    }
}
