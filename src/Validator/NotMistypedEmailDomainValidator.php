<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Validator;

use SpeedPuzzling\Web\Value\MistypedEmailDomain;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class NotMistypedEmailDomainValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof NotMistypedEmailDomain) {
            throw new UnexpectedTypeException($constraint, NotMistypedEmailDomain::class);
        }

        if (!is_string($value) || $value === '') {
            return;
        }

        $suggestion = MistypedEmailDomain::suggestionFor($value);

        if ($suggestion === null) {
            return;
        }

        $this->context->buildViolation($constraint->message)
            ->setParameter('{{ suggestion }}', $suggestion)
            ->addViolation();
    }
}
