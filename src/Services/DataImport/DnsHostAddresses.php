<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

readonly final class DnsHostAddresses implements HostAddresses
{
    public function of(string $host): array
    {
        // A name only: an address written as a "host" is never looked up
        if (preg_match('/^(?=.{1,253}\z)[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+\z/', $host) !== 1) {
            return [];
        }

        // The trailing dot: the name as it is, never completed by the resolver's search list
        $addresses = gethostbynamel($host . '.');

        return $addresses === false ? [] : $addresses;
    }
}
