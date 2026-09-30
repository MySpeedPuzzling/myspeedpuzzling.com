<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\SocialLogin;

/**
 * Microsoft-owned CONSUMER mailbox domains (Outlook.com, formerly Hotmail /
 * Live / MSN): for an address on one of these the personal Microsoft account
 * IS the mailbox, so the id_token's `email` is as good as Google's
 * email_verified - linking rule 2 may auto-link it
 * (docs/features/auth-hardening/microsoft-plan.md §D3).
 *
 * Any other address on a Microsoft account (e.g. a Gmail used as the account's
 * user name) is unverified: Microsoft documents the claim as "not guaranteed
 * to be correct".
 *
 * Exact match, lowercase - never a pattern (`outlook.xyz` is not Microsoft's).
 * Built 2026-09-30 from the production account domains plus Microsoft's
 * published Outlook.com domain list, keeping ONLY domains whose MX is
 * Outlook.com consumer mail (`*.olc.protection.outlook.com`). Left out on
 * purpose: hotmail.cz, outlook.cz, hotmail.nl (MX `*.mx.microsoft` = an
 * Exchange Online tenant, not Outlook.com), and domains without Microsoft MX
 * (outlook.ch, hotmail.pl, ...). To add one: `dig +short MX <domain>` must
 * answer `*.olc.protection.outlook.com`.
 */
final class MicrosoftConsumerMailDomains
{
    /** @var list<string> */
    public const array DOMAINS = [
        // Global
        'outlook.com',
        'hotmail.com',
        'live.com',
        'msn.com',
        'passport.com',
        'windowslive.com',
        // outlook.*
        'outlook.at',
        'outlook.be',
        'outlook.cl',
        'outlook.co.id',
        'outlook.co.il',
        'outlook.co.nz',
        'outlook.co.th',
        'outlook.com.ar',
        'outlook.com.au',
        'outlook.com.br',
        'outlook.com.gr',
        'outlook.com.tr',
        'outlook.com.vn',
        'outlook.de',
        'outlook.dk',
        'outlook.es',
        'outlook.fr',
        'outlook.hu',
        'outlook.ie',
        'outlook.in',
        'outlook.it',
        'outlook.jp',
        'outlook.kr',
        'outlook.lv',
        'outlook.my',
        'outlook.ph',
        'outlook.pt',
        'outlook.ro',
        'outlook.sa',
        'outlook.sg',
        'outlook.sk',
        // hotmail.*
        'hotmail.at',
        'hotmail.be',
        'hotmail.ca',
        'hotmail.ch',
        'hotmail.cl',
        'hotmail.co.id',
        'hotmail.co.il',
        'hotmail.co.jp',
        'hotmail.co.kr',
        'hotmail.co.nz',
        'hotmail.co.th',
        'hotmail.co.uk',
        'hotmail.co.za',
        'hotmail.com.ar',
        'hotmail.com.au',
        'hotmail.com.br',
        'hotmail.com.hk',
        'hotmail.com.tr',
        'hotmail.com.tw',
        'hotmail.com.vn',
        'hotmail.de',
        'hotmail.dk',
        'hotmail.es',
        'hotmail.fi',
        'hotmail.fr',
        'hotmail.gr',
        'hotmail.hu',
        'hotmail.it',
        'hotmail.lt',
        'hotmail.lv',
        'hotmail.my',
        'hotmail.no',
        'hotmail.ph',
        'hotmail.pt',
        'hotmail.rs',
        'hotmail.se',
        'hotmail.sg',
        'hotmail.sk',
        // live.*
        'live.at',
        'live.be',
        'live.ca',
        'live.ch',
        'live.cl',
        'live.cn',
        'live.co.kr',
        'live.co.uk',
        'live.co.za',
        'live.com.ar',
        'live.com.au',
        'live.com.mx',
        'live.com.my',
        'live.com.pt',
        'live.com.sg',
        'live.de',
        'live.dk',
        'live.fi',
        'live.fr',
        'live.hk',
        'live.ie',
        'live.in',
        'live.it',
        'live.jp',
        'live.nl',
        'live.no',
        'live.ru',
        'live.se',
    ];

    public static function contains(string $email): bool
    {
        $at = strrpos($email, '@');

        if ($at === false) {
            return false;
        }

        return in_array(strtolower(substr($email, $at + 1)), self::DOMAINS, true);
    }
}
