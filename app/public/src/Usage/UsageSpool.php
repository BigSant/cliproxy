<?php

namespace App\Usage;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Every usage record, as CLIProxyAPI's queue gave it, appended to a day file (usage/YYYY-MM-DD.jsonl in the traffic
 * directory, 0600) before it reaches the database. Taking from the queue removes the record, so this is its only other
 * copy. The day is marked pending before the database write and unmarked after it (write-ahead): a failed write or a
 * process killed in between leaves the mark, and the next pass replays the day (UsageRecord::$recordKey keeps it to
 * one row). Records arrive sanitized (UsageRecord::sanitize). Nothing here is ever deleted.
 */
class UsageSpool
{
    private const string PENDING = '.pending';

    /** @var array<string, int> */
    private array $broken = [];

    public function __construct(
        #[Autowire('%env(USAGE_SPOOL_DIR)%')]
        private readonly string $directory,
    ) {
    }

    /** @param list<array<string, mixed>> $records */
    public function dayOf(\DateTimeImmutable $now): string
    {
        return $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
    }

    /** @param list<array<string, mixed>> $records */
    public function append(array $records, \DateTimeImmutable $now): string
    {
        $day = $this->dayOf($now);
        $this->ensureDirectory();
        $lines = '';
        foreach ($records as $record) {
            $lines .= json_encode($record, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_THROW_ON_ERROR)."\n";
        }
        $path = $this->path($day);
        $new = !is_file($path);
        // A line a crash cut short stays a line of its own (counted as broken on replay), never glued to the next one.
        if (!$new && filesize($path) > 0) {
            $tail = fopen($path, 'rb');
            fseek($tail, -1, \SEEK_END);
            if ("\n" !== fread($tail, 1)) {
                $lines = "\n".$lines;
            }
            fclose($tail);
        }
        $handle = fopen($path, 'ab');
        // Every byte or an error: a short write (a full disk) must not pass for a stored batch.
        if (false === $handle || \strlen($lines) !== fwrite($handle, $lines) || !fflush($handle) || !fsync($handle)) {
            throw new \RuntimeException('Cannot write the usage spool '.$path);
        }
        fclose($handle);
        if ($new) {
            // Usage records name clients, sessions and prompts' sizes: readable by the owner only.
            chmod($path, 0o600);
        }

        return $day;
    }

    public function markPending(string $day): void
    {
        $this->ensureDirectory();
        $pending = $this->pendingDays();
        $pending[] = $day;
        $this->writePending($pending);
    }

    /** The day's records all reached the database. */
    public function unmarkPending(string $day): void
    {
        $this->writePending(array_values(array_diff($this->pendingDays(), [$day])));
    }

    /** @param list<string> $days */
    private function writePending(array $days): void
    {
        $content = implode("\n", array_unique($days)).([] === $days ? '' : "\n");
        $temporary = $this->directory.'/'.self::PENDING.'.tmp';
        // Written whole and renamed: a crash never leaves half a list.
        if (\strlen($content) !== @file_put_contents($temporary, $content) || !rename($temporary, $this->directory.'/'.self::PENDING)) {
            throw new \RuntimeException('Cannot write the usage spool\'s pending list');
        }
    }

    /** @return list<string> days whose records may be missing from the database */
    public function pendingDays(): array
    {
        $file = $this->directory.'/'.self::PENDING;

        return is_file($file) ? array_values(array_filter(array_map('trim', file($file) ?: []), static fn (string $day) => 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $day))) : [];
    }

    /**
     * The records of one day, in batches.
     *
     * @return \Generator<list<array<string, mixed>>>
     */
    public function read(string $day, int $batch = 500): \Generator
    {
        $path = $this->path($day);
        if (!is_file($path)) {
            return;
        }
        $handle = fopen($path, 'rb');
        $records = [];
        $this->broken[$day] = 0;
        while (false !== ($line = fgets($handle))) {
            if ('' === trim($line)) {
                continue;
            }
            $record = json_decode($line, true);
            if (\is_array($record)) {
                $records[] = $record;
            } else {
                ++$this->broken[$day];
            }
            if (\count($records) >= $batch) {
                yield $records;
                $records = [];
            }
        }
        fclose($handle);
        if ([] !== $records) {
            yield $records;
        }
    }

    /** Lines of a day that are not a record (cut short by a crash), counted by the last read(). */
    public function brokenLines(string $day): int
    {
        return $this->broken[$day] ?? 0;
    }

    /** Below this much free space a batch is not trusted to fit (a byte would, in a block already taken). */
    private const int MIN_FREE_BYTES = 64 * 1_048_576;

    /** Whether a batch can be appended now: the directory writable, with room to spare. */
    public function writable(): bool
    {
        try {
            $this->ensureDirectory();
            $free = @disk_free_space($this->directory);

            return is_writable($this->directory) && false !== $free && $free >= self::MIN_FREE_BYTES;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Whether the day's file takes an append now: an empty line written and synced (read() skips empty lines). */
    public function canAppend(string $day): bool
    {
        try {
            $this->ensureDirectory();
            $path = $this->path($day);
            $new = !is_file($path);
            $handle = @fopen($path, 'ab');
            $ok = false !== $handle && 1 === @fwrite($handle, "\n") && fflush($handle) && fsync($handle);
            if (false !== $handle) {
                fclose($handle);
            }
            if ($ok && $new) {
                chmod($path, 0o600);
            }

            return $ok;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<string> every day in the spool, oldest first */
    public function days(): array
    {
        $days = [];
        foreach (glob($this->directory.'/*.jsonl') ?: [] as $file) {
            $days[] = basename($file, '.jsonl');
        }
        sort($days);

        return $days;
    }

    private function path(string $day): string
    {
        return $this->directory.'/'.$day.'.jsonl';
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0o750, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Cannot create the usage spool '.$this->directory);
        }
    }
}
