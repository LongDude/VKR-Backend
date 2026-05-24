<?php

namespace App\Security;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final class ApiLogoutSubscriber implements EventSubscriberInterface
{
    public function onLogout(LogoutEvent $event): void
    {
        if ('/api/logout' !== $event->getRequest()->getPathInfo()) {
            return;
        }

        $event->setResponse(new JsonResponse(null, JsonResponse::HTTP_NO_CONTENT));
    }

    public static function getSubscribedEvents(): array
    {
        return [
            LogoutEvent::class => ['onLogout', 128],
        ];
    }
}
