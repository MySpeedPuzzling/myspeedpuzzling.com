<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\TestDouble;

use SpeedPuzzling\Web\Services\Email\DnsRecordLookup;

/**
 * No DNS in tests: a domain answers only what a test scripted for it, anything else "lookup failed" - so every check
 * fails open (example.com itself publishes a null MX). Scripted answers are static so they survive the kernel reboot
 * between a browser's requests; script only reserved `.test` domains, so no test can disturb another.
 */
final class ScriptedDnsRecordLookup implements DnsRecordLookup
{
    /** @var array<string, array<int, list<array<string, mixed>>>> */
    private static array $records = [];

    public int $lookups = 0;

    /**
     * @param list<array<string, mixed>> $records
     */
    public static function script(string $domain, int $type, array $records): void
    {
        self::$records[$domain][$type] = $records;
    }

    public function records(string $domain, int $type): null|array
    {
        $this->lookups++;

        return self::$records[$domain][$type] ?? null;
    }
}
