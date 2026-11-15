<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

/**
 * The addresses a host name stands for - asked before a request leaves for it, so the request can be refused when
 * one of them is not a public one, and pinned to the address that was checked (GoogleSheetFile).
 */
interface HostAddresses
{
    /**
     * @return list<string> IPv4 addresses; empty when the name does not resolve
     */
    public function of(string $host): array;
}
