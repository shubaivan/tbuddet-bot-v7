<?php

namespace App\EventSubscriber;

use App\Service\TelegramUserService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;

class RequestSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private LoggerInterface $graylogLogger,
        private TelegramUserService $telegramUserService
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            RequestEvent::class => 'onKernelRequest',
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            // don't do anything if it's not the main request
            return;
        }

        $request = $event->getRequest();
        if ('' === $content = $request->getContent()) {
            return;
        }

        try {
            $content = json_decode($content, true, 512, \JSON_BIGINT_AS_STRING | \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return;
        }

        if (!\is_array($content)) {
            return;
        }

        if (isset($content['message']['from'])) {
            $this->telegramUserService->initUser($content['message']['from']);
        }

        $this->graylogLogger->info('Pure request', ['request' => $content]);
    }
}
