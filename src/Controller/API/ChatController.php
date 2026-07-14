<?php

namespace App\Controller\API;

use App\Controller\API\Request\ChatMessageRequest;
use App\Controller\API\Request\ChatOperatorRequest;
use App\Service\Analytics\TelegramNotifier;
use App\Service\SupportChat\BudgetGuard;
use App\Service\SupportChat\ChatAssistantService;
use App\Service\SupportChat\PricingService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: 'api/v1/chat')]
class ChatController extends AbstractController
{
    /**
     * AI consultant reply. Body: {"messages":[{"role":"user","content":"..."}]}.
     * Guarded by per-IP rate limits + a global daily UAH budget ceiling.
     */
    #[Route('/message', name: 'public_chat_message', methods: [Request::METHOD_POST])]
    public function message(
        #[MapRequestPayload] ChatMessageRequest $payload,
        Request $request,
        ChatAssistantService $assistant,
        RateLimiterFactory $chatLimiter,
        RateLimiterFactory $chatDailyLimiter,
        BudgetGuard $budget,
        PricingService $pricing,
        LoggerInterface $logger,
    ): JsonResponse {
        $ip = $request->getClientIp() ?? 'anon';

        // 1) Per-IP per-minute limit — stop fast abuse.
        if (!$chatLimiter->create($ip)->consume(1)->isAccepted()) {
            return $this->json([
                'reply' => 'Забагато повідомлень поспіль 🙏 Зачекайте, будь ласка, хвилинку або натисніть «Зв\'язатися з оператором».',
                'suggestOperator' => true,
                'usage' => ['input' => 0, 'output' => 0],
            ], 429);
        }

        // 2) Per-IP per-day limit — stop steady abuse from a single address.
        if (!$chatDailyLimiter->create($ip)->consume(1)->isAccepted()) {
            return $this->json([
                'reply' => 'На сьогодні ви вичерпали ліміт повідомлень консультанту 🙏 Натисніть «Зв\'язатися з оператором» — ми допоможемо.',
                'suggestOperator' => true,
                'usage' => ['input' => 0, 'output' => 0],
            ], 429);
        }

        // 3) Global daily budget ceiling — don't burn the client's money.
        if ($budget->isOverBudget()) {
            $logger->warning('Support chat: daily AI budget exceeded — degraded.');
            $budget->notifyExceededOnce();

            return $this->json([
                'reply' => 'Консультант зараз завантажений. Натисніть «Зв\'язатися з оператором» — ми швидко допоможемо. Дякуємо! 🙏',
                'suggestOperator' => true,
                'usage' => ['input' => 0, 'output' => 0],
            ]);
        }

        $result = $assistant->reply($payload->messages);
        $budget->add($pricing->costUah($result['usage']['input'], $result['usage']['output']));

        return $this->json($result);
    }

    /**
     * "Connect an operator" handoff. Body: {"name","phone","messages":[...]}.
     * Summarizes the conversation and posts the lead to the «Заявки ArtBeton» group.
     */
    #[Route('/operator', name: 'public_chat_operator', methods: [Request::METHOD_POST])]
    public function operator(
        #[MapRequestPayload] ChatOperatorRequest $payload,
        Request $request,
        ChatAssistantService $assistant,
        RateLimiterFactory $chatLimiter,
        BudgetGuard $budget,
        PricingService $pricing,
        TelegramNotifier $notifier,
        LoggerInterface $logger,
    ): JsonResponse {
        $ip = $request->getClientIp() ?? 'anon';

        if (!$chatLimiter->create($ip)->consume(1)->isAccepted()) {
            return $this->json([
                'ok' => false,
                'message' => 'Забагато запитів поспіль 🙏 Зачекайте хвилинку і спробуйте ще раз.',
            ], 429);
        }

        // Summarize the dialogue for the operator (skip the model call when
        // over budget — the lead itself must still go through).
        $summary = '';
        if ($payload->messages && !$budget->isOverBudget()) {
            $res = $assistant->summarize($payload->messages);
            $summary = trim($res['summary']);
            $budget->add($pricing->costUah($res['usage']['input'], $res['usage']['output']));
        }

        $text = "🟢 <b>Запит на оператора з чату (artbeton.market)</b>\n"
            .'👤 '.TelegramNotifier::esc($payload->name)."\n"
            .'📞 '.TelegramNotifier::esc($payload->phone);
        if ('' !== $summary) {
            $text .= "\n\n📝 ".TelegramNotifier::esc($summary);
        }

        $sent = $notifier->send($text);
        if (!$sent) {
            $logger->error('Support chat: operator handoff notify failed.', ['name' => $payload->name]);
        }

        return $this->json([
            'ok' => true,
            'message' => 'Дякуємо! Оператор звʼяжеться з вами найближчим часом.',
        ]);
    }
}
