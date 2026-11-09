<?php

namespace App\Routing;

use App\CliProxy\Account;
use App\CliProxy\ManagementClient;
use App\Entity\QuotaSnapshot;
use App\Quota\QuotaWindow;

/**
 * Soonest-reset-first routing without a CLIProxyAPI fork. CLIProxyAPI serves a request from the accounts of the
 * highest priority first; this gives the highest priority to the account whose weekly limit resets soonest, so its
 * tokens are spent before they expire, and the lowest to accounts whose 5-hour or weekly limit is used up. Session
 * affinity still keeps a running session on its account.
 */
class SoonestResetPrioritizer
{
    public function __construct(private readonly ManagementClient $client)
    {
    }

    /**
     * @param list<Account>                $accounts
     * @param array<string, QuotaSnapshot> $snapshots by auth index
     *
     * @return array<string, int> the priority each account has now, by account name
     */
    public function apply(array $accounts, array $snapshots): array
    {
        $priorities = self::priorities($accounts, $snapshots);
        foreach ($accounts as $account) {
            $priority = $priorities[$account->name] ?? null;
            if (null !== $priority && $priority !== $account->priority) {
                $this->client->updateFields($account->name, ['priority' => $priority]);
            }
        }

        return $priorities;
    }

    /**
     * Per provider: exhausted accounts get 0, the rest 1… in reverse order of their weekly reset (sooner = higher).
     * An account without a known reset ranks after those with one.
     *
     * @param list<Account>                $accounts
     * @param array<string, QuotaSnapshot> $snapshots
     *
     * @return array<string, int>
     */
    public static function priorities(array $accounts, array $snapshots): array
    {
        $byProvider = [];
        foreach ($accounts as $account) {
            if (!$account->disabled) {
                $byProvider[$account->provider][] = $account;
            }
        }
        $priorities = [];
        foreach ($byProvider as $providerAccounts) {
            $usable = [];
            foreach ($providerAccounts as $account) {
                $snapshot = $snapshots[$account->authIndex] ?? null;
                $exhausted = false;
                foreach ($snapshot?->windows() ?? [] as $window) {
                    if (\in_array($window->key, [QuotaWindow::FIVE_HOUR, QuotaWindow::WEEK], true) && $window->isExhausted()) {
                        $exhausted = true;
                    }
                }
                if ($exhausted) {
                    $priorities[$account->name] = 0;
                } else {
                    $usable[] = [$account, $snapshot?->window(QuotaWindow::WEEK)?->resetsAt];
                }
            }
            usort($usable, static function (array $a, array $b): int {
                if (null === $a[1] || null === $b[1]) {
                    return (null === $a[1]) <=> (null === $b[1]) ?: strcmp($a[0]->name, $b[0]->name);
                }

                return $a[1] <=> $b[1] ?: strcmp($a[0]->name, $b[0]->name);
            });
            $priority = \count($usable);
            foreach ($usable as [$account]) {
                $priorities[$account->name] = $priority--;
            }
        }

        return $priorities;
    }
}
