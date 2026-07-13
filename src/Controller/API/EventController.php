<?php

declare(strict_types=1);

namespace App\Controller\API;

use App\Service\Analytics\ActivityService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Події активності з сайту (візит, кліки). Значущі — одразу в Telegram-групу
 * менеджерів, решта — у погодинний дайджест.
 * Тіло: {"type":"visit|view_catalog|view_product|add_to_cart|begin_checkout|click_phone|click_telegram|open_chat","page":"..."}
 */
#[Route(path: 'api/v1/event')]
class EventController extends AbstractController
{
    #[Route('', name: 'public_activity_event', methods: [Request::METHOD_POST])]
    public function event(Request $request, ActivityService $activity, RateLimiterFactory $eventLimiter): JsonResponse
    {
        $ip = $request->getClientIp() ?? 'anon';
        if (!$eventLimiter->create($ip)->consume(1)->isAccepted()) {
            return $this->json(['ok' => false], 429);
        }

        $data = json_decode($request->getContent() ?: '[]', true);
        if (!\is_array($data)) {
            return $this->json(['ok' => false], 400);
        }

        $type = (string) ($data['type'] ?? '');
        $page = trim((string) ($data['page'] ?? ''));
        if ('' === $type || !$activity->isKnownType($type)) {
            return $this->json(['ok' => false], 400);
        }

        $activity->record($type, $ip, '' !== $page ? $page : null);

        return $this->json(['ok' => true]);
    }
}
