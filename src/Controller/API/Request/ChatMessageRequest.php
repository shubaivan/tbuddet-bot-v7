<?php

namespace App\Controller\API\Request;

use Symfony\Component\Validator\Constraints as Assert;

class ChatMessageRequest
{
    /**
     * Conversation history from the widget: [{role:'user'|'assistant', content:string}, ...].
     * Structure is sanitized/bounded server-side in ChatAssistantService::reply().
     *
     * @var array<int, array{role?: string, content?: string}>
     */
    #[Assert\NotNull(message: 'Історія повідомлень обовʼязкова.')]
    #[Assert\Count(min: 1, minMessage: 'Повідомлення не може бути порожнім.')]
    #[Assert\Count(max: 100, maxMessage: 'Забагато повідомлень в історії.')]
    public array $messages = [];
}
