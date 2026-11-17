<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Value\CompetitorPageSettings;
use SpeedPuzzling\Web\Value\QrTagMode;

/**
 * The "Competitor pages & QR codes" card of the competition toolkit hub (docs/features/competitions-management/toolkit/
 * B-qr-competitor-page.md §2).
 */
final class CompetitorPageSettingsFormData
{
    public null|QrTagMode $qrTagMode = null;

    public bool $competitorPages = false;

    public bool $liveResultsBeforePublishing = false;

    public bool $timeProposals = false;

    public static function fromSettings(CompetitorPageSettings $settings): self
    {
        $data = new self();
        $data->qrTagMode = $settings->qrTagMode;
        $data->competitorPages = $settings->competitorPages;
        $data->liveResultsBeforePublishing = $settings->liveResultsBeforePublishing;
        $data->timeProposals = $settings->timeProposals;

        return $data;
    }

    public function toSettings(): CompetitorPageSettings
    {
        return new CompetitorPageSettings(
            competitorPages: $this->competitorPages,
            liveResultsBeforePublishing: $this->liveResultsBeforePublishing,
            timeProposals: $this->timeProposals,
            qrTagMode: $this->qrTagMode,
        );
    }
}
