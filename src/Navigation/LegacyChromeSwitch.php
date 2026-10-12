<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * Test-only switch (implementation plan decision 34, review F1): the Twig global `nav_omit_legacy_chrome`. Off
 * everywhere; only the MENU-01 weight probe's kernel replaces this service with one that is on, which drops today's
 * header (R10's legacy chrome) and its critical CSS rules, so the probe measures exactly the chrome NAV-06 will ship
 * against the P0-02 baseline. Deleted by NAV-06 together with the old header.
 */
final readonly class LegacyChromeSwitch
{
    public function __construct(
        private bool $omitLegacyChrome = false,
    ) {
    }

    public function omitsLegacyChrome(): bool
    {
        return $this->omitLegacyChrome;
    }
}
