<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Validator;

use Attribute;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/**
 * The password rules for every place a user picks a password (README §Auth
 * features at launch): long enough to survive offline cracking, not a trivially
 * guessable string, and not one of the passwords already in a public breach
 * corpus.
 *
 * NotCompromisedPassword calls haveibeenpwned with a k-anonymous prefix;
 * skipOnError keeps a sign-in flow working when that service is unreachable
 * (and the test env disables it outright, see config/packages/test/validator.php).
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class StrongPassword extends Compound
{
    public const int MINIMUM_LENGTH = 12;

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
            new Assert\PasswordStrength(),
            new Assert\NotCompromisedPassword(skipOnError: true),
        ];
    }
}
