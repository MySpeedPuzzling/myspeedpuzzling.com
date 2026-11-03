<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * An e-mail address whose domain is a typo of a big mail provider ("gmail.coma", "hotmail.con", "gnail.com",
 * "hotmatil.com") - none of our e-mails would ever reach it (docs/features/mass-mailing/README.md "Mistyped
 * domains"). The forms refuse it with the corrected address as a suggestion, and
 * `myspeedpuzzling:suppress-mistyped-email-addresses` suppresses the ones already stored.
 *
 * Deliberately narrow, so a real address is never refused:
 * - a top-level domain that does not exist and is one key away from ".com" (".con", ".coma", ".comm", ".cmo", …), on
 *   any domain;
 * - one typo (a letter missing, added, changed or two swapped) away from one of the providers below - but never a
 *   domain that is itself a real mailbox domain (ymail.com, mail.com, …). Providers with short names (me.com,
 *   aol.com, web.de) are left out: one typo away from them are real domains.
 */
final class MistypedEmailDomain
{
    /**
     * Never a real top-level domain; each one is a typo of ".com"
     */
    private const array MISTYPED_COM_ENDINGS = ['con', 'coma', 'comm', 'cmo', 'ocm', 'vom', 'xom', 'comn', 'coom', 'cpm', 'clm', 'c0m'];

    /**
     * Big providers with names long enough that one typo away is no other real mail domain
     */
    private const array PROVIDERS = [
        'gmail.com',
        'googlemail.com',
        'hotmail.com',
        'hotmail.co.uk',
        'outlook.com',
        'icloud.com',
        'yahoo.com',
        'seznam.cz',
        'centrum.cz',
        'protonmail.com',
        'btinternet.com',
        'comcast.net',
        'sbcglobal.net',
        'bigpond.com',
        't-online.de',
        'wanadoo.fr',
        'laposte.net',
    ];

    /**
     * Real mail domains one typo away from a provider above - never refused
     */
    private const array REAL_LOOKALIKES = [
        'ymail.com',
        'mail.com',
        'email.com',
        'gmx.com',
        'cloud.com',
        'hotmail.co.za',
        'yahoo.co',
        'yahoo.cz',
        'outlook.cz',
        'outlook.co',
        'seznam.sk',
        'centrum.sk',
        'protonmail.ch',
        'hotmail.it',
        'hotmail.fr',
        'hotmail.de',
        'hotmail.es',
    ];

    /**
     * The corrected address, or null when the domain is not a typo we know
     */
    public static function suggestionFor(string $email): null|string
    {
        $email = trim($email);
        $at = strrpos($email, '@');

        if ($at === false || $at === 0) {
            return null;
        }

        $local = substr($email, 0, $at);
        $domain = mb_strtolower(rtrim(substr($email, $at + 1), '.'));

        if ($domain === '' || in_array($domain, self::PROVIDERS, true) || in_array($domain, self::REAL_LOOKALIKES, true)) {
            return null;
        }

        $corrected = self::correctedDomain($domain);

        return $corrected === null ? null : $local . '@' . $corrected;
    }

    public static function isMistyped(string $email): bool
    {
        return self::suggestionFor($email) !== null;
    }

    private static function correctedDomain(string $domain): null|string
    {
        $lastDot = strrpos($domain, '.');

        if ($lastDot !== false && in_array(substr($domain, $lastDot + 1), self::MISTYPED_COM_ENDINGS, true)) {
            $withCom = substr($domain, 0, $lastDot) . '.com';

            // "gnail.con" → "gmail.com"
            return self::closestProvider($withCom) ?? $withCom;
        }

        return self::closestProvider($domain);
    }

    private static function closestProvider(string $domain): null|string
    {
        if (in_array($domain, self::PROVIDERS, true)) {
            return $domain;
        }

        foreach (self::PROVIDERS as $provider) {
            if (self::isOneTypoApart($domain, $provider)) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Exactly one letter missing, added or changed, or two neighbours swapped
     */
    private static function isOneTypoApart(string $a, string $b): bool
    {
        $lengthA = strlen($a);
        $lengthB = strlen($b);

        if (abs($lengthA - $lengthB) > 1 || $a === $b) {
            return false;
        }

        if ($lengthA === $lengthB) {
            $differences = [];

            for ($i = 0; $i < $lengthA; $i++) {
                if ($a[$i] !== $b[$i]) {
                    $differences[] = $i;
                }
            }

            if (count($differences) === 1) {
                return true;
            }

            return count($differences) === 2
                && $differences[1] === $differences[0] + 1
                && $a[$differences[0]] === $b[$differences[1]]
                && $a[$differences[1]] === $b[$differences[0]];
        }

        [$shorter, $longer] = $lengthA < $lengthB ? [$a, $b] : [$b, $a];

        for ($i = 0; $i < strlen($longer); $i++) {
            if (substr($longer, 0, $i) . substr($longer, $i + 1) === $shorter) {
                return true;
            }
        }

        return false;
    }
}
