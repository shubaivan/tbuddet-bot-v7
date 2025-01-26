<?php

namespace App\Validator;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints\ChoiceValidator;

class UserLanguageValidator extends ChoiceValidator
{
    public function __construct(private RequestStack $requestStack) {}

    public function validate(mixed $value, Constraint $constraint): void
    {
        if ($this->requestStack->getCurrentRequest()
            && $this->requestStack->getCurrentRequest()->headers
            && $this->requestStack->getCurrentRequest()->headers->has(UserLanguage::USER_LANGUAGE)
        ) {
            parent::validate($this->requestStack->getCurrentRequest()->headers->get(UserLanguage::USER_LANGUAGE), $constraint);
        }
    }
}