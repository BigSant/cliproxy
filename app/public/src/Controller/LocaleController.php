<?php

namespace App\Controller;

use App\EventListener\LocaleListener;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** LT | EN: remembers the language in this browser for a year and goes back to the page it was picked on. */
class LocaleController extends AbstractController
{
    #[Route('/locale/{locale}', name: 'app_locale', requirements: ['locale' => 'lt|en'], methods: ['GET'])]
    public function __invoke(string $locale, Request $request): RedirectResponse
    {
        // Back to the same page of this hub only: the path of a referrer on this host, never another site (nor a path
        // a browser reads as one, //host or /\host).
        $referer = parse_url((string) $request->headers->get('referer'));
        $back = \is_array($referer) && ($referer['host'] ?? null) === $request->getHost() && preg_match('#^/(?![/\\\\])#', $referer['path'] ?? '')
            ? $referer['path'].(isset($referer['query']) ? '?'.$referer['query'] : '')
            : $this->generateUrl('app_dashboard');
        $response = new RedirectResponse($back);
        $response->headers->setCookie(Cookie::create(LocaleListener::COOKIE, $locale, new \DateTimeImmutable('+1 year'),
            secure: $request->isSecure(), sameSite: Cookie::SAMESITE_LAX));

        return $response;
    }
}
