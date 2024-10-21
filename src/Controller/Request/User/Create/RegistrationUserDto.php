<?php

namespace App\Controller\Request\User\Create;

use App\Error\ErrorCodeEnum;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

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
    #[Assert\NotBlank]
    private $last_name;

    #[Assert\Type('string')]
    #[Assert\NotBlank(message: 'Password is required')]
    private $password;

    #[Assert\Type('string')]
    #[Assert\NotBlank]
    private $password_repeat;

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

    /**
     * @return mixed
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * @param mixed $password
     * @return RegistrationUserDto
     */
    public function setPassword($password)
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @return mixed
     */
    public function getPasswordRepeat()
    {
        return $this->password_repeat;
    }

    /**
     * @param mixed $password_repeat
     * @return RegistrationUserDto
     */
    public function setPasswordRepeat($password_repeat)
    {
        $this->password_repeat = $password_repeat;

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
