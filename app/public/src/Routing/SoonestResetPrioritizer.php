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
    public function apply(array $accounts, array $snapshots, ?\DateTimeImmutable $now = null): array
    {
        $priorities = self::priorities($accounts, $snapshots, $now ?? new \DateTimeImmutable());
        foreach ($accounts as $account) {
            $priority = $priorities[$account->name] ?? null;
            if (null !== $priority && $priority !== $account->priority) {
                $this->client->updateFields($account->name, ['priority' => $priority]);
            }
        }

        return $priorities;
    }

    /**
     * Per provider, three bands: accounts with room first, ordered by when their week resets (sooner = higher); then
     * accounts whose 5-hour window is nearly used up (90 %+), so a new session does not start where it would soon hit
     * the limit; last, with 0, accounts that are used up, that CLIProxyAPI holds back or that need a new sign-in. A window whose reset has passed
     * counts as fresh. An account without a known reset ranks after those with one in its band.
     *
     * @param list<Account>                $accounts
     * @param array<string, QuotaSnapshot> $snapshots
     *
     * @return array<string, int>
     */
    public static function priorities(array $accounts, array $snapshots, \DateTimeImmutable $now): array
    {
        $byProvider = [];
        foreach ($accounts as $account) {
            if (!$account->disabled) {
                $byProvider[$account->provider][] = $account;
            }
        }
        $priorities = [];
        foreach ($byProvider as $providerAccounts) {
            $fresh = $hot = [];
            foreach ($providerAccounts as $account) {
                $snapshot = $snapshots[$account->authIndex] ?? null;
                $fiveHour = $snapshot?->window(QuotaWindow::FIVE_HOUR);
                $week = $snapshot?->window(QuotaWindow::WEEK);
                if ($account->quotaBlocked || $account->requiresSignIn || $fiveHour?->isExhausted($now) || $week?->isExhausted($now)) {
                    $priorities[$account->name] = 0;
                    continue;
                }
                $reset = null !== $week?->resetsAt && $week->resetsAt > $now ? $week->resetsAt : null;
                if (null !== $fiveHour && $fiveHour->usedAt($now) >= 90) {
                    $hot[] = [$account, $reset];
                } else {
                    $fresh[] = [$account, $reset];
                }
            }
            $priority = \count($fresh) + \count($hot);
            foreach ([$fresh, $hot] as $band) {
                usort($band, static function (array $a, array $b): int {
                    if (null === $a[1] || null === $b[1]) {
                        return (null === $a[1]) <=> (null === $b[1]) ?: strcmp($a[0]->name, $b[0]->name);
                    }

                    return $a[1] <=> $b[1] ?: strcmp($a[0]->name, $b[0]->name);
                });
                foreach ($band as [$account]) {
                    $priorities[$account->name] = $priority--;
                }
            }
        }

        return $priorities;
    }
}
