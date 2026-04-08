<?php

namespace App\Controller\Request\User\Create;

use Symfony\Component\Validator\Constraints as Assert;

class ForgotPasswordDto
{
    #[Assert\Type('string')]
    #[Assert\NotBlank(message: 'Email is required')]
    #[Assert\Email(message: 'Invalid email address')]
    private string $email;

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;

        return $this;
    }
}
