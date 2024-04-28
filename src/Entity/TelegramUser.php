<?php

namespace App\Entity;

use App\Repository\TelegramUserRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TelegramUserRepository::class)]
class TelegramUser
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255, nullable: false)]
    private ?string $telegram_id;
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $phone_number;
    #[ORM\Column(type: 'string', length: 255, nullable: false)]
    private string $first_name;
    #[ORM\Column(type: 'string', length: 255, nullable: false)]
    private string $last_name;
    #[ORM\Column(type: 'string', length: 255, nullable: false)]
    private string $username;
    #[ORM\Column(type: 'string', length: 255, nullable: false)]
    private string $language_code;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(?int $id): TelegramUser
    {
        $this->id = $id;

        return $this;
    }

    public function getTelegramId(): ?string
    {
        return $this->telegram_id;
    }

    public function setTelegramId(?string $telegram_id): TelegramUser
    {
        $this->telegram_id = $telegram_id;

        return $this;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phone_number;
    }

    public function setPhoneNumber(?string $phone_number): TelegramUser
    {
        $this->phone_number = $phone_number;

        return $this;
    }

    public function getFirstName(): string
    {
        return $this->first_name;
    }

    public function setFirstName(string $first_name): TelegramUser
    {
        $this->first_name = $first_name;

        return $this;
    }

    public function getLastName(): string
    {
        return $this->last_name;
    }

    public function setLastName(string $last_name): TelegramUser
    {
        $this->last_name = $last_name;

        return $this;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function setUsername(string $username): TelegramUser
    {
        $this->username = $username;

        return $this;
    }

    public function getLanguageCode(): string
    {
        return $this->language_code;
    }

    public function setLanguageCode(string $language_code): TelegramUser
    {
        $this->language_code = $language_code;

        return $this;
    }
}
