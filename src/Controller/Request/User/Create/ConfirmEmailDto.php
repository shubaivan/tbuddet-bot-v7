<?php

namespace App\Controller\Request\User\Create;

use App\Error\ErrorCodeEnum;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class ConfirmEmailDto
{
    #[Assert\Type('string')]
    #[Assert\NotBlank(message: 'Token is required')]
    private string $token;

    #[Assert\Type('string')]
    #[Assert\NotBlank(message: 'Password is required')]
    #[Assert\Length(min: 6, minMessage: 'Password must be at least {{ limit }} characters')]
    private string $password;

    #[Assert\Type('string')]
    #[Assert\NotBlank]
    private string $password_repeat;

    public function getToken(): string
    {
        return $this->token;
    }

    public function setToken(string $token): self
    {
        $this->token = $token;

        return $this;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): self
    {
        $this->password = $password;

        return $this;
    }

    public function getPasswordRepeat(): string
    {
        return $this->password_repeat;
    }

    public function setPasswordRepeat(string $password_repeat): self
    {
        $this->password_repeat = $password_repeat;

        return $this;
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context, mixed $payload): void
    {
        if ($this->password_repeat !== $this->password) {
            $context->buildViolation('password_repeat not confirmed')
                ->atPath('password_repeat')
                ->setCode(ErrorCodeEnum::PASSWORD_MISMATCH->value)
                ->addViolation();
        }
    }
}
