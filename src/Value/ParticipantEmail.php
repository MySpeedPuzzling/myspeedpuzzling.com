<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The organiser's e-mail address of a participant (`competition_participant.email`, docs/features/competitions-management/
 * participant-linking.md D15, §3.1) - the import's Email column and the participants sheet's E-mail column read it the
 * same way: trimmed and lower case (UserAccount::canonicalizeEmail()), at most MAX_LENGTH characters, one address that
 * looks like one. Families may share an address, so nothing checks it against the other people of the event - nor
 * against accounts (D10: nothing ever says whether an address has an account).
 */
final class ParticipantEmail
{
    public const int MAX_LENGTH = 254;

    /**
     * @return null|string|false null = no address (an empty cell), false = not an address, else the canonical address
     */
    public static function read(null|string $value): null|string|false
    {
        if ($value === null) {
            return null;
        }

        $email = self::canonicalize($value);

        if ($email === '') {
            return null;
        }

        if (mb_strlen($email) > self::MAX_LENGTH || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        return $email;
    }

    public static function canonicalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
