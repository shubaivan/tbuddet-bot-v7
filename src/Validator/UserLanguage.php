<?php

namespace App\Validator;

use App\Controller\API\Request\Enum\UserLanguageEnum;
use App\Entity\Enum\RoleEnum;
use Symfony\Component\Validator\Constraints\Choice;

#[\Attribute]
class UserLanguage extends Choice
{
    const USER_LANGUAGE = 'User-language';
    public string $message = 'The {{ value }} you selected is not a valid {{ choices }}.';

    public function __construct(
        array|string $options = [],
        ?array $choices = null,
        callable|string|null $callback = null,
        ?bool $multiple = null,
        ?bool $strict = null,
        ?int $min = null,
        ?int $max = null,
        ?string $message = null,
        ?string $multipleMessage = null,
        ?string $minMessage = null,
        ?string $maxMessage = null,
        ?array $groups = null,
        mixed $payload = null,
        ?bool $match = null
    ) {
        if (!$choices && !$choices) {
            $choices = $this->getUserLanguageEnumValues();
        }

        parent::__construct($options, $choices, $callback, $multiple, $strict, $min, $max, $message, $multipleMessage, $minMessage, $maxMessage, $groups, $payload, $match);
    }

    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }

    public function validatedBy(): string
    {
        return UserLanguageValidator::class;
    }

    public function getUserLanguageEnumValues(): array
    {
        return array_column(UserLanguageEnum::cases(), 'value');
    }
}
