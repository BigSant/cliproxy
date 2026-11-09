<?php

namespace App\View;

use App\CliProxy\Account;
use App\Entity\QuotaSnapshot;
use App\Quota\QuotaFetcher;
use App\Quota\QuotaWindow;

/**
 * The accounts table of Apžvalga and Paskyros: every account with its newest limits. Accounts are grouped by
 * provider; within one, the account whose weekly limit resets soonest comes first (the one to spend first).
 */
final class AccountRows
{
    /**
     * @param list<Account>                $accounts
     * @param array<string, QuotaSnapshot> $snapshots by auth index
     *
     * @return list<array{account: Account, snapshot: ?QuotaSnapshot, windows: array<string, QuotaWindow>, stale: bool}>
     */
    public static function build(array $accounts, array $snapshots, \DateTimeImmutable $now): array
    {
        $rows = [];
        foreach ($accounts as $account) {
            $snapshot = $snapshots[$account->authIndex] ?? null;
            $windows = [];
            foreach ($snapshot?->windows() ?? QuotaFetcher::fromHeaders($account->signals) as $window) {
                $windows[$window->key] = $window;
            }
            $rows[] = [
                'account' => $account,
                'snapshot' => $snapshot,
                'windows' => $windows,
                'stale' => null === $snapshot || $now->getTimestamp() - $snapshot->fetchedAt->getTimestamp() > 1800,
            ];
        }
        usort($rows, static function (array $a, array $b): int {
            $reset = static fn (array $row) => ($row['windows'][QuotaWindow::WEEK] ?? null)?->resetsAt?->getTimestamp() ?? \PHP_INT_MAX;

            return [$a['account']->provider, $a['account']->disabled, $reset($a), $a['account']->label()]
                <=> [$b['account']->provider, $b['account']->disabled, $reset($b), $b['account']->label()];
        });

        return $rows;
    }
}
