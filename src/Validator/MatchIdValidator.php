<?php

namespace App\Validator;

use App\Error\ErrorCodeEnum;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

class MatchIdValidator extends ConstraintValidator
{
    public function __construct(private readonly EntityManagerInterface $manager) {}

    public function validate($value, Constraint $constraint): void
    {
        if (!$constraint instanceof MatchId) {
            throw new UnexpectedTypeException($constraint, MatchId::class);
        }

        // custom constraints should ignore null and empty values to allow
        // other constraints (NotBlank, NotNull, etc.) to take care of that
        if ($value === null || $value === '') {
            return;
        }

        if (!$constraint->actualClass) {
            throw new \Exception('Class is required');
        }

        if (is_array($value)) {
            foreach ($value as $id) {
                if (!is_string($id)) {
                    throw new UnexpectedValueException($id, 'string');
                }
                $this->processId($constraint, $id);
            }
        } elseif (is_string($value)) {
            $this->processId($constraint, $value);
        } else {
            throw new UnexpectedValueException($value, 'string|array');
        }
    }

    public function processId(MatchId $constraint, $value): void
    {
        $criteria = [$constraint->property => $value];
        if ($constraint->extraCriteria) {
            $criteria += $constraint->extraCriteria;
        }

        $model = $this->manager->getRepository($constraint->actualClass)->findOneBy($criteria);
        if (!$model) {
            $this->context->buildViolation($constraint->message)
                ->setCode(ErrorCodeEnum::ENTITY_LOOKUP_IS_REQUESTED_BY_AN_INVALID_IDENTIFIER->value)
                ->atPath($constraint->property)
                ->addViolation();
        }
    }
}
