<?php

namespace App\Controller;

use App\Repository\AlertRepository;
use App\Settings\Settings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/** Įspėjimai: what needs the person now, and what did before. */
class AlertController extends AbstractController
{
    #[Route('/alerts', name: 'app_alerts', methods: ['GET'])]
    public function __invoke(AlertRepository $alerts): Response
    {
        return $this->render('alerts/index.html.twig', [
            'open' => $alerts->open(),
            'resolved' => $alerts->resolved(50),
        ]);
    }

    /**
     * The quarantined request logs get another try: the collector (which may write the traffic directory; the
     * dashboard only reads it) moves them back into logs/ on its next pass.
     */
    #[Route('/alerts/retry-quarantine', name: 'app_alerts_retry', methods: ['POST'])]
    public function retry(Request $request, Settings $settings, TranslatorInterface $translator): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('alerts', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }
        $settings->set(Settings::QUARANTINE_RETRY_REQUESTED, true);
        $this->addFlash('success', $translator->trans('alerts.flash.retry'));

        return $this->redirectToRoute('app_alerts');
    }

    /**
     * What is known now is seen – the broken spool lines, the lost records: their alert comes back only for new ones.
     * Nothing is deleted.
     */
    #[Route('/alerts/seen/{what}', name: 'app_alerts_seen', requirements: ['what' => 'broken|lost'], methods: ['POST'])]
    public function seen(string $what, Request $request, Settings $settings, TranslatorInterface $translator): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('alerts', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }
        if ('broken' === $what) {
            $settings->set(Settings::USAGE_BROKEN_LINES_SEEN, (array) ($settings->get(Settings::USAGE_BROKEN_LINES) ?? []));
        } else {
            $settings->set(Settings::USAGE_LOST_SEEN, (int) $settings->get(Settings::USAGE_LOST));
        }
        $this->addFlash('success', $translator->trans('alerts.flash.seen'));

        return $this->redirectToRoute('app_alerts');
    }
}
