<?php

namespace App\EventListener;

use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;

readonly class JWTAuthenticationSuccessListener
{
    public function __construct(private int $jwtTTL)
    {
    }

    public function onJWTAuthenticationSuccess(AuthenticationSuccessEvent $event): void
    {
        $payload = $event->getData();
        $payload['token_expiration'] = (new \DateTime())->getTimestamp() + $this->jwtTTL;
        $event->setData($payload);
    }
}
