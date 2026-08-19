<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Query\GetSelectableCompetitions;
use SpeedPuzzling\Web\Results\SelectableCompetition;
use SpeedPuzzling\Web\Twig\ImageThumbnailTwigExtension;
use SpeedPuzzling\Web\Value\CompetitionChoices;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the TomSelect payload of the "Competition / event" picker (add-time + edit-time forms):
 * one option card per selectable competition, editions grouped under an optgroup per series.
 * Options are emitted in the query's global rank order — TomSelect renders a series' block where its
 * first edition sits, so a series with a live edition shows up near the top while a long-dormant one
 * sinks with its last edition.
 *
 * Names and locations are organiser-authored, so every dynamic string is HTML-escaped here — the
 * picker renders option HTML as-is (`options_as_html`).
 */
readonly final class CompetitionChoicesBuilder
{
    public function __construct(
        private GetSelectableCompetitions $getSelectableCompetitions,
        private ImageThumbnailTwigExtension $imageThumbnail,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @param null|string $alwaysIncludeCompetitionId The competition the edited time is currently linked to —
     *        offered even when it is not (or no longer) publicly visible, so a re-save keeps the link.
     */
    public function build(null|string $alwaysIncludeCompetitionId = null): CompetitionChoices
    {
        $options = [];
        $optgroups = [];
        $ids = [];
        $seenSeries = [];

        foreach ($this->getSelectableCompetitions->all($alwaysIncludeCompetitionId) as $competition) {
            if ($competition->seriesId !== null && isset($seenSeries[$competition->seriesId]) === false) {
                $seenSeries[$competition->seriesId] = true;

                $optgroup = [
                    'value' => $competition->seriesId,
                    'label' => $competition->seriesName ?? '',
                ];

                if ($competition->seriesLogo !== null) {
                    $optgroup['logo'] = $this->imageThumbnail->thumbnailUrl($competition->seriesLogo, 'puzzle_small');
                }

                $optgroups[] = $optgroup;
            }

            $option = [
                'value' => $competition->id,
                'text' => $this->renderCard($competition),
                'keywords' => $this->keywords($competition),
            ];

            if ($competition->seriesId !== null) {
                $option['optgroup'] = $competition->seriesId;
            }

            $options[] = $option;
            $ids[$competition->id] = true;
        }

        return new CompetitionChoices($options, $optgroups, $ids);
    }

    private function renderCard(SelectableCompetition $competition): string
    {
        $img = '';

        if ($competition->logo !== null) {
            $logoUrl = self::escape($this->imageThumbnail->thumbnailUrl($competition->logo, 'puzzle_small'));

            $img = <<<HTML
<img alt="" class="img-fluid rounded-2 competition-option-logo" src="{$logoUrl}" loading="lazy" decoding="async" width="48" height="48">
HTML;
        }

        $date = '';

        if ($competition->dateFrom !== null) {
            $date = $competition->dateFrom->format('d.m.Y');

            if ($competition->dateTo !== null) {
                $date .= ' - ' . $competition->dateTo->format('d.m.Y');
            }
        }

        $liveBadge = '';

        if ($competition->eventStatus === 'live') {
            $liveBadge = '<span class="badge bg-success ms-1">' . self::escape($this->translator->trans('forms.competition_live_badge')) . '</span>';
        }

        $location = '';

        if ($competition->locationCountryCode !== null) {
            $location = '<span class="shadow-custom fi fi-' . $competition->locationCountryCode->name . ' me-2"></span>';
        }

        if ($competition->location !== null) {
            $location .= self::escape($competition->location);
        }

        $descriptionParts = [];

        // An edition card carries its series name, so the selected item in the control stays
        // self-descriptive ("EJJ #68" alone says nothing once the optgroup header is out of sight).
        if ($competition->seriesId !== null && $competition->seriesName !== null) {
            $descriptionParts[] = self::escape($competition->seriesName);
        }

        if ($location !== '') {
            $descriptionParts[] = $location;
        }

        $description = implode(' · ', $descriptionParts);
        $name = self::escape($competition->name);

        return <<<HTML
<div class="py-1 d-flex low-line-height competition-option">
    <div class="icon me-2">{$img}</div>
    <div class="pe-1">
        <div class="mb-1">
            <span class="h6">{$name}</span>
            <small class="text-muted">{$date}</small>{$liveBadge}
        </div>
        <div class="description"><small>{$description}</small></div>
    </div>
</div>
HTML;
    }

    /**
     * Plain-text search terms TomSelect matches besides the (tag-stripped) card text.
     */
    private function keywords(SelectableCompetition $competition): string
    {
        $parts = array_filter(
            [
                $competition->seriesName,
                $competition->seriesShortcut,
                $competition->name,
                $competition->shortcut,
                $competition->location,
            ],
            static fn (null|string $part): bool => $part !== null && trim($part) !== '',
        );

        return trim(implode(' ', $parts));
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES);
    }
}
