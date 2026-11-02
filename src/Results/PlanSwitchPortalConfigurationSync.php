<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class PlanSwitchPortalConfigurationSync
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        /** null on a dry run that would create it */
        public null|string $configurationId,
        public bool $created,
        public bool $dryRun,
        public array $payload,
    ) {
    }
}
