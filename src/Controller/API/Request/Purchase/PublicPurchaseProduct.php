<?php

namespace App\Controller\API\Request\Purchase;

use Symfony\Component\Validator\Constraints as Assert;

class PublicPurchaseProduct extends PurchaseProduct
{
    #[Assert\NotBlank]
    #[Assert\Type('string')]
    #[Assert\Length(min: 12, max: 12, minMessage: 'Phone cannot be less than {{ limit }} characters',
        maxMessage: 'Phone cannot be longer than {{ limit }} characters')]
    #[Assert\Regex(pattern: "/^[0-9]*$/", message: "Please use number only")]
    private $phone;

    public function getPhone()
    {
        return $this->phone;
    }

    public function setPhone($phone)
    {
        $this->phone = $phone;

        return $this;
    }
}