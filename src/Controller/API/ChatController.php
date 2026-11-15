<?php

namespace App\Controller\API;

use App\Controller\API\Request\ChatMessageRequest;
use App\Controller\API\Request\ChatOperatorRequest;
use App\Service\Analytics\TelegramNotifier;
use App\Service\SupportChat\BudgetGuard;
use App\Service\SupportChat\ChatDialogReport;
use App\Service\SupportChat\ChatDialogStore;
use App\Service\SupportChat\ChatPhone;
use App\Service\SupportChat\ChatAssistantService;
use App\Service\SupportChat\ConsultantUsage;
use App\Service\SupportChat\PricingService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapRequestPayload;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route(path: 'api/v1/chat')]
class ChatController extends AbstractController
{
    /**
     * Phone-first вхід у чат. Body: {"name"?, "phone", "page"?, "returning"?}.
     * Перевіряє номер, створює діалог, шле лід «💬 Новий чат» у тему «Магазин»
     * і повертає підписаний токен, без якого /message не відповідає.
     * Відповідь не залежить від того, чи знайомий нам номер, — перебором
     * нічого не дізнатися. returning=true — повернення з уже збереженим
     * номером (сесія протухла): лід вдруге не шлемо.
     */
    #[Route('/start', name: 'public_chat_start', methods: [Request::METHOD_POST])]
    public function start(
        Request $request,
        RateLimiterFactoryInterface $chatStartLimiter,
        ChatDialogStore $dialogs,
        TelegramNotifier $notifier,
        LoggerInterface $logger,
    ): JsonResponse {
        if (!$chatStartLimiter->create($request->getClientIp() ?? 'anon')->consume(1)->isAccepted()) {
            return $this->json(['ok' => false, 'message' => 'Забагато спроб поспіль 🙏 Зачекайте кілька хвилин або зателефонуйте: 068 517 00 77.'], 429);
        }

        $body = self::jsonBody($request);
        $phone = ChatPhone::normalize(\is_string($body['phone'] ?? null) ? $body['phone'] : '');
        if (null === $phone) {
            return $this->json(['ok' => false, 'message' => 'Вкажіть, будь ласка, справжній номер телефону — наприклад, 097 123 45 67 або +380 97 123 45 67.'], 422);
        }
        $name = \is_string($body['name'] ?? null) ? trim(mb_substr(strip_tags($body['name']), 0, 60)) : '';
        $page = \is_string($body['page'] ?? null) ? mb_substr($body['page'], 0, 300) : '';
        $returning = true === ($body['returning'] ?? false);

        $dialog = $dialogs->create($name, $phone, $page);

        if (!$returning) {
            $text = "💬 <b>Новий чат — клієнт залишив номер</b> (artbeton.market)\n"
                .'👤 '.('' !== $name ? TelegramNotifier::esc($name) : '<i>без імені</i>')."\n"
                .'📞 '.TelegramNotifier::esc($phone);
            if ('' !== $page) {
                $text .= "\n🌐 ".TelegramNotifier::esc($page);
            }
            if (!$notifier->send($text, TelegramNotifier::TOPIC_SHOP)) {
                $logger->error('Support chat: chat-start lead notify failed.', ['dialog' => $dialog['id']]);
            }
        }

        return $this->json(['ok' => true, 'token' => $dialog['token']]);
    }

    /**
     * Кінець діалогу з віджета (закрив чат / сховав вкладку). Надсилається
     * через sendBeacon як text/plain (без CORS-preflight). Body: {"token","messages"}.
     * Звіт «💬 Діалог завершено» йде рівно один раз — повтори ігноруються.
     */
    #[Route('/end', name: 'public_chat_end', methods: [Request::METHOD_POST])]
    public function end(Request $request, ChatDialogStore $dialogs, ChatDialogReport $report): Response
    {
        $body = self::jsonBody($request);
        $id = $dialogs->idFromToken(\is_string($body['token'] ?? null) ? $body['token'] : null);
        if (null !== $id) {
            $messages = \is_array($body['messages'] ?? null) ? \array_slice(array_values($body['messages']), -40) : null;
            $report->finish($id, 'closed', $messages);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * AI consultant reply. Body: {"messages":[{"role":"user","content":"..."}]}.
     * Guarded by per-IP rate limits + a global daily UAH budget ceiling.
     */
    #[Route('/message', name: 'public_chat_message', methods: [Request::METHOD_POST])]
    public function message(
        #[MapRequestPayload] ChatMessageRequest $payload,
        Request $request,
        ChatAssistantService $assistant,
        RateLimiterFactoryInterface $chatLimiter,
        RateLimiterFactoryInterface $chatDailyLimiter,
        BudgetGuard $budget,
        PricingService $pricing,
        ConsultantUsage $usage,
        LoggerInterface $logger,
        ChatDialogStore $dialogs,
    ): JsonResponse {
        $ip = $request->getClientIp() ?? 'anon';

        // 0) Phone-first: без токена з /start консультант не відповідає.
        $dialogId = $dialogs->idFromToken($request->headers->get('X-Chat-Token') ?? $payload->token);
        $dialog = null !== $dialogId ? $dialogs->get($dialogId) : null;
        if (null === $dialog) {
            return $this->json([
                'reply' => 'Щоб продовжити консультацію, залиште, будь ласка, номер телефону.',
                'suggestOperator' => false,
                'gate' => true,
                'usage' => ['input' => 0, 'output' => 0],
            ], 401);
        }
        // Діалог уже закрито (звіт пішов), а клієнт пише знову — продовжуємо новим діалогом без нового ліда.
        $newToken = null;
        if ($dialog['ended']) {
            $fresh = $dialogs->create((string) $dialog['name'], (string) $dialog['phone'], (string) $dialog['page']);
            $dialogId = $fresh['id'];
            $newToken = $fresh['token'];
            $dialog = $dialogs->get($dialogId) ?? $dialog;
        }

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

        $result = $assistant->reply($payload->messages, ['name' => (string) $dialog['name'], 'phone' => (string) $dialog['phone']]);
        $budget->add($pricing->costUah($result['usage']['input'], $result['usage']['output']));
        if ($result['usage']['input'] > 0) {
            $usage->reply($result['usage']['input'], $result['usage']['output']);
        }

        $history = $payload->messages;
        $history[] = ['role' => 'assistant', 'content' => $result['reply']];
        $dialogs->update($dialogId, static function (array $d) use ($result, $history): array {
            ++$d['questions'];
            ++$d['replies'];
            $d['input'] += $result['usage']['input'];
            $d['output'] += $result['usage']['output'];
            $d['links'] = array_values(array_unique([...(array) $d['links'], ...$result['links']]));
            $d['messages'] = \array_slice(array_values($history), -40);
            $d['lastAt'] = time();

            return $d;
        });

        unset($result['links']);
        if (null !== $newToken) {
            $result['token'] = $newToken;
        }

        return $this->json($result);
    }

    /**
     * "Connect an operator" handoff. Body: {"name","phone","messages":[...]}.
     * Summarizes the conversation and posts the lead to the «Консультант» topic of the managers group.
     */
    #[Route('/operator', name: 'public_chat_operator', methods: [Request::METHOD_POST])]
    public function operator(
        #[MapRequestPayload] ChatOperatorRequest $payload,
        Request $request,
        ChatAssistantService $assistant,
        RateLimiterFactoryInterface $chatLimiter,
        BudgetGuard $budget,
        PricingService $pricing,
        TelegramNotifier $notifier,
        ConsultantUsage $usage,
        LoggerInterface $logger,
        ChatDialogStore $dialogs,
    ): JsonResponse {
        $ip = $request->getClientIp() ?? 'anon';

        if (!$chatLimiter->create($ip)->consume(1)->isAccepted()) {
            return $this->json([
                'ok' => false,
                'message' => 'Забагато запитів поспіль 🙏 Зачекайте хвилинку і спробуйте ще раз.',
            ], 429);
        }

        // Телефон уже відомий з «входу» в чат — другої форми не треба, лише підтвердження.
        $dialogId = $dialogs->idFromToken($request->headers->get('X-Chat-Token') ?? $payload->token);
        $dialog = null !== $dialogId ? $dialogs->get($dialogId) : null;
        $name = '' !== trim($payload->name) ? trim($payload->name) : (string) ($dialog['name'] ?? '');
        $phone = '' !== trim($payload->phone) ? ChatPhone::normalize($payload->phone) : (isset($dialog['phone']) ? (string) $dialog['phone'] : null);
        if (null === $phone) {
            return $this->json(['ok' => false, 'message' => 'Вкажіть, будь ласка, коректний номер телефону.'], 422);
        }
        if (null !== $dialogId) {
            $dialogs->update($dialogId, static function (array $d): array {
                $d['operator'] = true;
                $d['lastAt'] = time();

                return $d;
            });
        }

        // Summarize the dialogue for the operator (skip the model call when
        // over budget — the lead itself must still go through).
        $summary = '';
        if ($payload->messages && !$budget->isOverBudget()) {
            $res = $assistant->summarize($payload->messages);
            $summary = trim($res['summary']);
            $budget->add($pricing->costUah($res['usage']['input'], $res['usage']['output']));
            if ($res['usage']['input'] > 0) {
                $usage->reply($res['usage']['input'], $res['usage']['output']);
            }
        }
        $usage->operatorRequest();

        $text = "🟢 <b>Запит на оператора з чату (artbeton.market)</b>\n"
            .'👤 '.('' !== $name ? TelegramNotifier::esc($name) : '<i>без імені</i>')."\n"
            .'📞 '.TelegramNotifier::esc($phone);
        if ('' !== $summary) {
            $text .= "\n\n📝 ".TelegramNotifier::esc($summary);
        }

        $sent = $notifier->send($text, TelegramNotifier::TOPIC_CONSULTANT);
        if (!$sent) {
            $logger->error('Support chat: operator handoff notify failed.', ['dialog' => $dialogId]);
        }

        return $this->json([
            'ok' => true,
            'message' => 'Дякуємо! Оператор звʼяжеться з вами найближчим часом.',
        ]);
    }

    /**
     * JSON із тіла незалежно від Content-Type (sendBeacon шле text/plain).
     *
     * @return array<string, mixed>
     */
    private static function jsonBody(Request $request): array
    {
        $raw = (string) $request->getContent();
        if ('' === $raw || \strlen($raw) > 200_000) {
            return [];
        }
        try {
            $data = json_decode($raw, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($data) ? $data : [];
    }
}
