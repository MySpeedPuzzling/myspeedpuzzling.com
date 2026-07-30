<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Validator;

use Attribute;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/**
 * The password rules for every place a user picks a password: at least 8
 * characters, nothing more (product decision 2026-07-30 - the entropy
 * estimator and the breach-corpus lookup rejected passwords users considered
 * fine, and the friction outweighed the benefit).
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class StrongPassword extends Compound
{
    public const int MINIMUM_LENGTH = 8;

    /**
     * @param array<string, mixed> $options
     *
     * @return list<Constraint>
     */
    protected function getConstraints(array $options): array
    {
        return [
            new Assert\NotBlank(),
            new Assert\Length(min: self::MINIMUM_LENGTH),
        ];
    }
}
