<?php

namespace App\Controller\Request\User\Create;

use Symfony\Component\Validator\Constraints as Assert;

class RegistrationUserDto
{
    #[Assert\Type('string')]
    #[Assert\NotBlank]
    #[Assert\Email]
    private $email;

    #[Assert\Type('string')]
    #[Assert\NotBlank]
    private $first_name;

    #[Assert\Type('string')]
    private $last_name = null;

    #[Assert\NotBlank]
    #[Assert\Type('string')]
    #[Assert\Length(min: 12, max: 12, minMessage: 'Phone cannot be less than {{ limit }} characters',
        maxMessage: 'Phone cannot be longer than {{ limit }} characters')]
    #[Assert\Regex(pattern: "/^[0-9]*$/", message: "Please use number only")]
    private string $phone;

    /**
     * @return mixed
     */
    public function getEmail()
    {
        return $this->email;
    }

    /**
     * @param mixed $email
     * @return RegistrationUserDto
     */
    public function setEmail($email)
    {
        $this->email = $email;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getFirstName()
    {
        return $this->first_name;
    }

    /**
     * @param mixed $first_name
     * @return RegistrationUserDto
     */
    public function setFirstName($first_name)
    {
        $this->first_name = $first_name;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getLastName()
    {
        return $this->last_name;
    }

    /**
     * @param mixed $last_name
     * @return RegistrationUserDto
     */
    public function setLastName($last_name)
    {
        $this->last_name = $last_name;

        return $this;
    }

    public function getPhone(): string
    {
        return $this->phone;
    }

    public function setPhone(string $phone): RegistrationUserDto
    {
        $this->phone = $phone;

        return $this;
    }
}
