<?php

namespace App\Service;

use App\Controller\API\Request\Enum\UserLanguageEnum;
use App\Validator\UserLanguage;
use Symfony\Component\HttpFoundation\RequestStack;

class LocalizationService
{

    public function __construct(private RequestStack $requestStack) {}

    public function getLanguage(?string $language_code = null): ?UserLanguageEnum
    {
        $language = UserLanguageEnum::EN;

        if ($language_code && UserLanguageEnum::tryFrom($language_code)) {
            return UserLanguageEnum::tryFrom($language_code);
        }

        if ($this->requestStack->getCurrentRequest()
            && $this->requestStack->getCurrentRequest()->headers->has(UserLanguage::USER_LANGUAGE)) {
            $language = $this->requestStack->getCurrentRequest()->headers->get(UserLanguage::USER_LANGUAGE);
            $language = UserLanguageEnum::tryFrom($language);
        }

        return $language;
    }
}