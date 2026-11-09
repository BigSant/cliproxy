<?php

namespace App\Controller;

use App\Repository\AlertRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
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
}
