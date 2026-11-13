<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Sandbox;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Entity\UserAccount;
use SpeedPuzzling\Web\Exceptions\SandboxAccountNotFound;

/**
 * One account of production for the sandbox (docs/features/sandbox/README.md "Copying a real account"): the sign-in
 * (e-mail + password hash, so the person signs in there with their own password) and the basic profile. READ-ONLY.
 * Nothing else of the person - results, collections, pictures - ever leaves production.
 */
final readonly class SandboxAccountExport
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array{email: string, passwordHash: null|string, emailVerified: bool, name: null|string, code: string, country: null|string, locale: null|string, timezone: null|string}
     *
     * @throws SandboxAccountNotFound
     */
    public function byEmail(string $email): array
    {
        /** @var false|array{email: string, password: null|string, email_verified_at: null|string, name: null|string, code: string, country: null|string, locale: null|string, timezone: null|string} $row */
        $row = $this->connection->fetchAssociative(
            <<<SQL
SELECT ua.email, ua.password, ua.email_verified_at, p.name, p.code, p.country, p.locale, p.timezone
FROM user_account ua
JOIN player p ON p.user_id = ua.user_id
WHERE ua.email = :email
SQL,
            ['email' => UserAccount::canonicalizeEmail($email)],
        );

        if ($row === false) {
            throw new SandboxAccountNotFound();
        }

        return [
            'email' => $row['email'],
            'passwordHash' => $row['password'],
            'emailVerified' => $row['email_verified_at'] !== null,
            'name' => $row['name'],
            'code' => $row['code'],
            'country' => $row['country'],
            'locale' => $row['locale'],
            'timezone' => $row['timezone'],
        ];
    }
}
