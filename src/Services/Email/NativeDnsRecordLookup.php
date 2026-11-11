<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Email;

/**
 * PHP's resolver: `dns_get_record()` answers [] for a domain without records of that type or a domain that does not
 * exist (HOST_NOT_FOUND / NO_DATA), and false with a warning for a resolver failure (TRY_AGAIN, NO_RECOVERY)
 */
final readonly class NativeDnsRecordLookup implements DnsRecordLookup
{
    public function records(string $domain, int $type): null|array
    {
        $records = @dns_get_record($domain . '.', $type);

        if ($records === false) {
            return null;
        }

        /** @var array<array<string, mixed>> $records */
        return array_values($records);
    }
}
