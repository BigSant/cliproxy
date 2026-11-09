<?php

namespace App\Controller;

use App\Repository\UsageRecordRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** Naudojimas: tokens, cache reads and writes per day, account and model, and the session affinity check. */
class UsageController extends AbstractController
{
    public const array RANGES = ['24h' => ['-24 hours', '24 val.'], '7d' => ['-7 days', '7 d.'], '30d' => ['-30 days', '30 d.'], '90d' => ['-90 days', '90 d.']];

    #[Route('/usage', name: 'app_usage', methods: ['GET'])]
    public function __invoke(Request $request, UsageRecordRepository $usage, ClockInterface $clock): Response
    {
        $range = (string) $request->query->get('range', '7d');
        if (!isset(self::RANGES[$range])) {
            $range = '7d';
        }
        $since = $clock->now()->modify(self::RANGES[$range][0]);
        $days = $usage->byDay($since);

        return $this->render('usage/index.html.twig', [
            'range' => $range,
            'ranges' => self::RANGES,
            'totals' => $usage->totals($since),
            'days' => $days,
            'dayMax' => max([1, ...array_map(static fn (array $day) => $day['total'], $days)]),
            'accounts' => $usage->byAccount($since),
            'models' => $usage->byModel($since),
            'affinity' => $usage->affinity($since),
        ]);
    }
}
