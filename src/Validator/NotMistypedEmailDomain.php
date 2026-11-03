<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Validator;

use Attribute;
use Symfony\Component\Validator\Constraint;

/**
 * Refuses an address whose domain is a typo of a big mail provider (`MistypedEmailDomain`), suggesting the corrected one
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class NotMistypedEmailDomain extends Constraint
{
    public string $message = 'auth.email_mistyped';
}
