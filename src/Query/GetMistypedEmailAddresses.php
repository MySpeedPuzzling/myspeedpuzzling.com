<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Value\MistypedEmailDomain;
use SpeedPuzzling\Web\Value\SesEvent;

/**
 * Stored addresses none of our e-mails can reach and that nothing suppresses yet: account addresses with a mistyped
 * provider domain (MistypedEmailDomain) and addresses SES answered "5.4.4 Invalid domain" for before that bounce
 * suppressed on its own (docs/features/mass-mailing/README.md "Mistyped domains")
 */
readonly final class GetMistypedEmailAddresses
{
    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @return array<string, null|string> lowercased address => the corrected one (null: SES said the domain is invalid)
     */
    public function notSuppressed(): array
    {
        // Every account address in one statement, checked in PHP - the rule lives in one place (MistypedEmailDomain)
        /** @var list<string> $accountEmails */
        $accountEmails = $this->database->executeQuery(<<<SQL
SELECT LOWER(TRIM(user_account.email))
FROM user_account
WHERE NOT EXISTS (SELECT 1 FROM email_suppression WHERE email_suppression.email = LOWER(TRIM(user_account.email)))
SQL)->fetchFirstColumn();

        $found = [];

        foreach ($accountEmails as $email) {
            $suggestion = MistypedEmailDomain::suggestionFor($email);

            if ($suggestion !== null) {
                $found[$email] = $suggestion;
            }
        }

        $bounced = $this->database->executeQuery(<<<SQL
SELECT DISTINCT LOWER(TRIM(email_audit_log.recipient_email)), email_audit_log.bounce_reason
FROM email_audit_log
WHERE email_audit_log.bounce_reason LIKE '%Invalid domain%'
  AND NOT EXISTS (SELECT 1 FROM email_suppression WHERE email_suppression.email = LOWER(TRIM(email_audit_log.recipient_email)))
SQL)->fetchAllNumeric();

        foreach ($bounced as [$email, $reason]) {
            if (is_string($email) && is_string($reason) && preg_match(SesEvent::INVALID_DOMAIN_PATTERN, $reason) === 1) {
                $found[$email] ??= MistypedEmailDomain::suggestionFor($email);
            }
        }

        ksort($found);

        return $found;
    }
}
