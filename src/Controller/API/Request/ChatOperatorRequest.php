<?php

namespace App\Controller\API\Request;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * "Connect an operator" handoff from the support-chat widget.
 * The customer leaves a name + phone; the conversation is optional context
 * that the backend summarizes for the operator.
 */
class ChatOperatorRequest
{
    #[Assert\NotBlank(message: 'Вкажіть, будь ласка, імʼя.')]
    #[Assert\Length(max: 120)]
    public string $name = '';

    #[Assert\NotBlank(message: 'Вкажіть, будь ласка, номер телефону.')]
    #[Assert\Length(max: 40)]
    public string $phone = '';

    /**
     * Optional conversation context: [{role, content}, ...].
     *
     * @var array<int, array{role?: string, content?: string}>
     */
    #[Assert\Count(max: 100, maxMessage: 'Забагато повідомлень в історії.')]
    public array $messages = [];
}
