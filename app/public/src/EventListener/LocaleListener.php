<?php

namespace App\EventListener;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * The dashboard's language: the one picked with LT | EN (a cookie, so it outlasts the session and holds on the login
 * page), else the default (Lithuanian). Runs before Symfony's own locale listeners, which then pass it to the
 * translator.
 */
final readonly class LocaleListener
{
    public const string COOKIE = 'locale';

    /** @param list<string> $enabledLocales */
    public function __construct(
        #[Autowire('%kernel.enabled_locales%')]
        private array $enabledLocales,
    ) {
    }

    #[AsEventListener(event: KernelEvents::REQUEST, priority: 20)]
    public function onRequest(RequestEvent $event): void
    {
        $locale = (string) $event->getRequest()->cookies->get(self::COOKIE);
        if (\in_array($locale, $this->enabledLocales, true)) {
            $event->getRequest()->setLocale($locale);
        }
    }
}
