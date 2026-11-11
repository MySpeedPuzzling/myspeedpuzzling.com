<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Validator;

use Attribute;
use Symfony\Component\Validator\Constraint;

/**
 * Refuses an address whose domain accepts no e-mail at all (`EmailDomainMailCheck`: a null MX, or no mail host)
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class EmailDomainAcceptsMail extends Constraint
{
    public string $message = 'auth.email_domain_no_mail';
}
