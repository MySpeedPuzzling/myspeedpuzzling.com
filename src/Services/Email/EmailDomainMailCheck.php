<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Email;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Does the domain of an address accept e-mail at all? Asked by the forms that take an address (registration, e-mail
 * change, newsletter signup) after `MistypedEmailDomain`, for the domains no typo rule knows - SES bounced our mails to
 * gmail.de ("Unable to lookup DNS") and only suppressed the address after a second bounce a day later.
 *
 * Refuses only on clear evidence, never on a failed lookup:
 * - a "null MX" (RFC 7505: one MX record pointing at "."), the domain's own statement that it takes no mail;
 * - no MX record and no address record either (RFC 5321 §5.1: without an MX, mail goes to the domain's A/AAAA) -
 *   also every domain that does not exist.
 * Any lookup error or timeout = accepts mail (fail open). Answers are cached for a day per domain, failures never.
 */
final readonly class EmailDomainMailCheck
{
    private const int CACHE_SECONDS = 86400;

    public function __construct(
        private DnsRecordLookup $dns,
        private CacheInterface $emailDomainMailCache,
    ) {
    }

    public function acceptsNoMail(string $email): bool
    {
        $domain = self::domainOf($email);

        if ($domain === null) {
            return false;
        }

        try {
            $verdict = $this->emailDomainMailCache->get(
                'domain_' . md5($domain),
                function (ItemInterface $item) use ($domain): string {
                    $verdict = $this->lookUp($domain);

                    // A failed lookup is never cached: throwing from the callback stores nothing
                    if ($verdict === null) {
                        throw new DnsLookupFailed();
                    }

                    $item->expiresAfter(self::CACHE_SECONDS);

                    return $verdict;
                },
            );
        } catch (DnsLookupFailed) {
            return false;
        }

        return $verdict === 'no_mail';
    }

    public static function domainOf(string $email): null|string
    {
        $email = trim($email);
        $at = strrpos($email, '@');

        if ($at === false) {
            return null;
        }

        $domain = mb_strtolower(rtrim(substr($email, $at + 1), '.'));

        // A bare name ("localhost") or an IP literal is not ours to judge
        if ($domain === '' || !str_contains($domain, '.') || str_starts_with($domain, '[')) {
            return null;
        }

        return $domain;
    }

    /**
     * @return null|'mail'|'no_mail' null = a lookup failed
     */
    private function lookUp(string $domain): null|string
    {
        $mx = $this->dns->records($domain, DNS_MX);

        if ($mx === null) {
            return null;
        }

        if ($mx !== []) {
            foreach ($mx as $record) {
                $target = $record['target'] ?? '';

                if (is_string($target) && rtrim($target, '.') !== '') {
                    return 'mail';
                }
            }

            return 'no_mail';
        }

        foreach ([DNS_A, DNS_AAAA] as $type) {
            $records = $this->dns->records($domain, $type);

            if ($records === null) {
                return null;
            }

            if ($records !== []) {
                return 'mail';
            }
        }

        return 'no_mail';
    }
}
