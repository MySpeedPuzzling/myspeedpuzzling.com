<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Email;

interface DnsRecordLookup
{
    /**
     * The domain's records of one type (`DNS_MX`, `DNS_A`, `DNS_AAAA`): an empty list when the domain has none or
     * does not exist, null when the lookup itself failed (a resolver error or timeout)
     *
     * @return null|list<array<string, mixed>>
     */
    public function records(string $domain, int $type): null|array;
}
