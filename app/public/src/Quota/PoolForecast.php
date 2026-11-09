<?php

namespace App\Quota;

use App\CliProxy\Account;
use App\Entity\QuotaSnapshot;

/**
 * A provider's accounts as one pool (Kvotos): what is left of one limit window across all of them (each account is
 * 100 %), as recorded and as projected. The projection spends the recent burn rate from the account that resets
 * soonest first (the hub's soonest-reset-first routing), and gives an account its window back when it resets.
 */
final class PoolForecast
{
    public const array WINDOWS = [
        QuotaWindow::WEEK => 604800,
        QuotaWindow::FIVE_HOUR => 18000,
        QuotaWindow::WEEK_FABLE => 604800,
    ];

    /** @var list<array{t: int, v: float}> */
    public array $recorded = [];
    /** @var list<array{t: int, v: float}> */
    public array $projected = [];
    /** @var list<array{t: int, v: float}> recorded burn, % per hour */
    public array $drain = [];
    /** @var array<string, array{label: string, recorded: list<array{t: int, v: float}>, projected: list<array{t: int, v: float}>}> */
    public array $accounts = [];
    /** @var list<array{t: int, v: float, gain: float}> resets in the projection: when, the pool after, how much came back */
    public array $resets = [];
    public float $poolNow = 0;
    public float $poolMax = 0;
    /** % per hour over the burn window */
    public float $burn = 0;
    public ?int $poolEmptyAt = null;
    /** @var array{t: int, label: string}|null */
    public ?array $firstEmpty = null;
    /** @var array{t: int, gain: float}|null */
    public ?array $nextReset = null;
    /** Accounts left out: no limits read for this window yet. */
    public int $unknown = 0;

    /**
     * @param list<Account>                     $accounts the provider's enabled accounts
     * @param array<string, list<QuotaSnapshot>> $history  by auth index, oldest first, reaching back before $from
     */
    public static function build(array $accounts, array $history, string $window, int $now, int $from, int $to, int $burnHours, int $points = 240): self
    {
        $forecast = new self();
        $windowSeconds = self::WINDOWS[$window] ?? 604800;
        $step = max(60, intdiv($to - $from, $points));
        $series = [];
        foreach ($accounts as $account) {
            $values = [];
            foreach ($history[$account->authIndex] ?? [] as $snapshot) {
                $quota = $snapshot->window($window);
                if (null !== $quota) {
                    $values[] = ['t' => $snapshot->fetchedAt->getTimestamp(), 'used' => min(100.0, max(0.0, $quota->used)), 'reset' => $quota->resetsAt?->getTimestamp()];
                }
            }
            if ([] === $values) {
                ++$forecast->unknown;
                continue;
            }
            $series[$account->authIndex] = ['label' => $account->label(), 'values' => $values];
        }
        if ([] === $series) {
            return $forecast;
        }
        $forecast->poolMax = 100.0 * \count($series);

        // Recorded: every account's last known value at each point; a point before an account's first value is left out.
        $burnSince = $now - $burnHours * 3600;
        $state = [];
        foreach ($series as $authIndex => $account) {
            $forecast->accounts[$authIndex] = ['label' => $account['label'], 'recorded' => [], 'projected' => []];
            $last = end($account['values']);
            // A window whose reset has passed since the last reading is whole again.
            if (null !== $last['reset'] && $last['reset'] <= $now) {
                $last = ['t' => $now, 'used' => 0.0, 'reset' => $last['reset'] + $windowSeconds * (intdiv($now - $last['reset'], $windowSeconds) + 1)];
            }
            $forecast->poolNow += 100 - $last['used'];
            $spent = 0.0;
            $previous = null;
            foreach ($account['values'] as $value) {
                if (null !== $previous && $value['t'] > $burnSince && $value['used'] > $previous['used']) {
                    $spent += $value['used'] - $previous['used'];
                }
                $previous = $value;
            }
            $state[$authIndex] = [
                'remaining' => 100 - $last['used'],
                'reset' => $last['reset'] ?? $now + $windowSeconds,
                'burn' => $spent / max(1, $burnHours),
                'label' => $account['label'],
            ];
        }
        $forecast->burn = array_sum(array_column($state, 'burn'));
        // One cursor per account: the points and the values both go forward in time.
        $cursor = array_fill_keys(array_keys($series), -1);
        for ($t = $from; $t <= min($now, $to); $t += $step) {
            $pool = 0.0;
            $known = true;
            $at = [];
            foreach ($series as $authIndex => $account) {
                $values = $account['values'];
                while (isset($values[$cursor[$authIndex] + 1]) && $values[$cursor[$authIndex] + 1]['t'] <= $t) {
                    ++$cursor[$authIndex];
                }
                if ($cursor[$authIndex] < 0) {
                    $known = false;
                    break;
                }
                $at[$authIndex] = 100 - $values[$cursor[$authIndex]]['used'];
                $pool += $at[$authIndex];
            }
            if ($known) {
                $forecast->recorded[] = ['t' => $t, 'v' => $pool];
                foreach ($at as $authIndex => $remaining) {
                    $forecast->accounts[$authIndex]['recorded'][] = ['t' => $t, 'v' => $remaining];
                }
            }
        }
        if ($now >= $from && $now <= $to) {
            $forecast->recorded[] = ['t' => $now, 'v' => $forecast->poolNow];
            foreach ($state as $authIndex => $account) {
                $forecast->accounts[$authIndex]['recorded'][] = ['t' => $now, 'v' => $account['remaining']];
            }
        }
        $forecast->drain = self::drain($series, max($from, $now - 14 * 86400), min($now, $to), max($step, 3600));

        // Projected: spend the pool's burn from the soonest-resetting account with something left.
        $forecast->projected[] = ['t' => $now, 'v' => $forecast->poolNow];
        foreach ($state as $authIndex => $account) {
            $forecast->accounts[$authIndex]['projected'][] = ['t' => $now, 'v' => $account['remaining']];
        }
        $soonest = min(array_column($state, 'reset'));
        $forecast->nextReset = ['t' => $soonest, 'gain' => 0.0];
        $horizon = max($to, $now + 14 * 86400);
        $projectStep = min($step, 900);
        for ($t = $now + $projectStep; $t <= $horizon; $t += $projectStep) {
            $gain = 0.0;
            foreach ($state as $authIndex => &$account) {
                while ($account['reset'] <= $t) {
                    $back = 100 - $account['remaining'];
                    if ($account['reset'] === $soonest && null !== $forecast->nextReset && 0.0 === $forecast->nextReset['gain']) {
                        $forecast->nextReset['gain'] = $back;
                    }
                    $gain += $back;
                    $account['remaining'] = 100.0;
                    $account['reset'] += $windowSeconds;
                }
            }
            unset($account);
            $spend = $forecast->burn * $projectStep / 3600;
            uasort($state, static fn (array $a, array $b) => $a['reset'] <=> $b['reset']);
            foreach ($state as &$account) {
                if ($spend <= 0) {
                    break;
                }
                $taken = min($spend, $account['remaining']);
                $account['remaining'] -= $taken;
                $spend -= $taken;
                if ($account['remaining'] <= 0.0001 && null === $forecast->firstEmpty && $forecast->burn > 0) {
                    $forecast->firstEmpty = ['t' => $t, 'label' => $account['label']];
                }
            }
            unset($account);
            $pool = array_sum(array_column($state, 'remaining'));
            if ($pool <= 0.0001 && null === $forecast->poolEmptyAt && $forecast->burn > 0) {
                $forecast->poolEmptyAt = $t;
            }
            if ($t <= $to) {
                $forecast->projected[] = ['t' => $t, 'v' => $pool];
                foreach ($state as $authIndex => $account) {
                    $forecast->accounts[$authIndex]['projected'][] = ['t' => $t, 'v' => $account['remaining']];
                }
                if ($gain >= 1) {
                    $forecast->resets[] = ['t' => $t, 'v' => $pool, 'gain' => $gain];
                }
            }
        }
        if (null !== $forecast->nextReset && 0.0 === $forecast->nextReset['gain']) {
            $forecast->nextReset['gain'] = 100 - ($state[array_key_first($state)]['remaining'] ?? 100);
        }

        return $forecast;
    }

    /**
     * The pool's recorded burn per bucket: increases of every account's used share, in % per hour.
     *
     * @param array<string, array{label: string, values: list<array{t: int, used: float, reset: ?int}>}> $series
     *
     * @return list<array{t: int, v: float}>
     */
    private static function drain(array $series, int $from, int $to, int $bucket): array
    {
        $buckets = [];
        for ($t = $from - $from % $bucket; $t <= $to; $t += $bucket) {
            $buckets[$t] = 0.0;
        }
        foreach ($series as $account) {
            $previous = null;
            foreach ($account['values'] as $value) {
                if (null !== $previous && $value['used'] > $previous['used'] && $value['t'] >= $from) {
                    $key = $value['t'] - $value['t'] % $bucket;
                    if (isset($buckets[$key])) {
                        $buckets[$key] += $value['used'] - $previous['used'];
                    }
                }
                $previous = $value;
            }
        }
        $drain = [];
        foreach ($buckets as $t => $spent) {
            $drain[] = ['t' => $t, 'v' => $spent / ($bucket / 3600)];
        }

        return $drain;
    }
}
