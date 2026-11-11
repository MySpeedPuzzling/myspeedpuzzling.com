<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Validator;

use SpeedPuzzling\Web\Services\Email\EmailDomainMailCheck;
use SpeedPuzzling\Web\Value\MistypedEmailDomain;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

final class EmailDomainAcceptsMailValidator extends ConstraintValidator
{
    public function __construct(
        private readonly EmailDomainMailCheck $mailCheck,
    ) {
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof EmailDomainAcceptsMail) {
            throw new UnexpectedTypeException($constraint, EmailDomainAcceptsMail::class);
        }

        // A typo has its own message with the corrected address (NotMistypedEmailDomain); an invalid address its own
        if (!is_string($value) || $value === '' || MistypedEmailDomain::isMistyped($value) || !str_contains($value, '@')) {
            return;
        }

        if (!$this->mailCheck->acceptsNoMail($value)) {
            return;
        }

        $this->context->buildViolation($constraint->message)
            ->setParameter('{{ domain }}', (string) EmailDomainMailCheck::domainOf($value))
            ->addViolation();
    }
}
