<?php

namespace App\Controller;

use App\Repository\AlertRepository;
use App\Settings\Settings;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

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
    public function retry(Request $request, Settings $settings): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('alerts', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }
        $settings->set(Settings::QUARANTINE_RETRY_REQUESTED, true);
        $this->addFlash('success', 'Karantino failai bus paimti dar kartą per kelias sekundes.');

        return $this->redirectToRoute('app_alerts');
    }

    /** The broken spool lines known now are seen: the alert comes back only for new ones. The lines stay in the files. */
    #[Route('/alerts/broken-lines-seen', name: 'app_alerts_broken_seen', methods: ['POST'])]
    public function brokenLinesSeen(Request $request, Settings $settings): RedirectResponse
    {
        if (!$this->isCsrfTokenValid('alerts', (string) $request->request->get('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token');
        }
        $settings->set(Settings::USAGE_BROKEN_LINES_SEEN, (array) ($settings->get(Settings::USAGE_BROKEN_LINES) ?? []));
        $this->addFlash('success', 'Pažymėta. Įspėjimas užsidarys per minutę.');

        return $this->redirectToRoute('app_alerts');
    }
}
