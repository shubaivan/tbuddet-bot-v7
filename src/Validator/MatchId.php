<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute]
class MatchId extends Constraint
{
    public string $actualClass;
    public ?string $property;
    public array $extraCriteria = [];
    public string $message = 'Invalid entity identifier';

    public function __construct(
        string $actualClass,
        ?string $property = null,
        array $extraCriteria = [],
        mixed $options = null,
        array $groups = null,
        mixed $payload = null,
        ?string $message = null
    ) {
        parent::__construct($options, $groups, $payload);
        $this->message = $message ?? $this->message;
        $this->actualClass = $actualClass;
        $this->property = $property ?: 'id';
        $this->extraCriteria = $extraCriteria;
    }
}
