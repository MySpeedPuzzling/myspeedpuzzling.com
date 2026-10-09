<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetSelectableCompetitions;
use SpeedPuzzling\Web\Query\GetSeriesEditionChoices;
use SpeedPuzzling\Web\Results\SelectableCompetition;
use SpeedPuzzling\Web\Results\SeriesEditionChoice;
use SpeedPuzzling\Web\Services\EventsPage\EventsPageDates;
use SpeedPuzzling\Web\Twig\ImageThumbnailTwigExtension;
use SpeedPuzzling\Web\Value\CompetitionChoices;
use SpeedPuzzling\Web\Value\CompetitionPick;
use SpeedPuzzling\Web\Value\CompetitionPickKind;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds the TomSelect payload of the "Competition / event" picker (add-time, edit-time, stopwatch finish -
 * docs/features/events-page/high-frequency-series.md "The form"): one option card per one-time event and per series,
 * in the query's global order; an edition only as the current pick or a refused submit's choice, under an optgroup of
 * its series. Option values are CompetitionPick field values (`<uuid>`, `series:<uuid>`, `edition:<uuid>`).
 *
 * The edition cards of the typed search (S1) and of the preview's short list come from here too, so a fetched edition
 * looks exactly like an offered one.
 *
 * Names and locations are organiser-authored, so every dynamic string is HTML-escaped here — the picker renders option
 * HTML as-is (`options_as_html`).
 */
readonly final class CompetitionChoicesBuilder
{
    public function __construct(
        private GetSelectableCompetitions $getSelectableCompetitions,
        private GetSeriesEditionChoices $getSeriesEditionChoices,
        private ImageThumbnailTwigExtension $imageThumbnail,
        private TranslatorInterface $translator,
        private EventsPageDates $dates,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param null|CompetitionPick $current The edited time's link, or a deep link's pick — offered even when it is not
     *        (or no longer) publicly visible, so a re-save keeps the link
     * @param null|CompetitionPick $submitted What a refused submit held — an edition picked by typing or from the short
     *        list is offered again (only while publicly visible), so the control does not come back empty
     */
    public function build(null|CompetitionPick $current = null, null|CompetitionPick $submitted = null): CompetitionChoices
    {
        $options = [];
        $optgroups = [];
        $values = [];
        $seenOptgroups = [];

        $submittedEditionId = $submitted?->kind === CompetitionPickKind::Edition ? $submitted->id : null;

        foreach ($this->getSelectableCompetitions->all($current, $submittedEditionId) as $competition) {
            $value = $competition->pick()->fieldValue();

            if (isset($values[$value])) {
                continue;
            }

            $option = [
                'value' => $value,
                'text' => match ($competition->kind) {
                    SelectableCompetition::KIND_SERIES => $this->renderSeriesCard($competition),
                    default => $this->renderCompetitionCard($competition),
                },
                'keywords' => $this->keywords($competition),
            ];

            if ($competition->kind === SelectableCompetition::KIND_EDITION && $competition->seriesId !== null) {
                $option['optgroup'] = $competition->seriesId;

                if (isset($seenOptgroups[$competition->seriesId]) === false) {
                    $seenOptgroups[$competition->seriesId] = true;
                    $optgroups[] = $this->optgroup($competition->seriesId, $competition->seriesName ?? '', $competition->seriesLogo);
                }
            }

            $options[] = $option;
            $values[$value] = true;
        }

        return new CompetitionChoices(
            $options,
            $optgroups,
            $values,
            $current,
            $this->getSeriesEditionChoices->isSelectableEdition(...),
        );
    }

    /**
     * Editions as TomSelect options and their series' optgroups - the payload of the typed search (S1)
     *
     * @param list<SeriesEditionChoice> $editions
     * @return array{options: list<array{value: string, text: string, keywords: string, optgroup: string}>, optgroups: list<array{value: string, label: string, logo?: string}>}
     */
    public function editionsPayload(array $editions): array
    {
        $options = [];
        $optgroups = [];

        foreach ($editions as $edition) {
            $options[] = $this->editionOption($edition);
            $optgroups[$edition->seriesId] ??= $this->editionOptgroup($edition);
        }

        return [
            'options' => $options,
            'optgroups' => array_values($optgroups),
        ];
    }

    /**
     * @return array{value: string, text: string, keywords: string, optgroup: string}
     */
    public function editionOption(SeriesEditionChoice $edition): array
    {
        $parts = [self::escape($edition->seriesName)];
        $location = $this->location($edition->locationCountryCode, $edition->location);

        if ($location !== '') {
            $parts[] = $location;
        }

        return [
            'value' => $edition->pick()->fieldValue(),
            'text' => $this->card(
                $edition->logo,
                $edition->name,
                self::dateRange($edition->dayFrom, $edition->dayTo),
                $edition->isLiveOn($this->clock->now()),
                $parts,
            ),
            'keywords' => self::joinKeywords([$edition->seriesName, $edition->seriesShortcut, $edition->name, $edition->location]),
            'optgroup' => $edition->seriesId,
        ];
    }

    /**
     * @return array{value: string, label: string, logo?: string}
     */
    public function editionOptgroup(SeriesEditionChoice $edition): array
    {
        return $this->optgroup($edition->seriesId, $edition->seriesName, $edition->seriesLogo);
    }

    /**
     * @return array{value: string, label: string, logo?: string}
     */
    private function optgroup(string $seriesId, string $seriesName, null|string $seriesLogo): array
    {
        $optgroup = [
            'value' => $seriesId,
            'label' => $seriesName,
        ];

        if ($seriesLogo !== null) {
            $optgroup['logo'] = $this->imageThumbnail->thumbnailUrl($seriesLogo, 'puzzle_small');
        }

        return $optgroup;
    }

    /**
     * A one-time event, or an edition offered as the current pick — the card the picker has always shown
     */
    private function renderCompetitionCard(SelectableCompetition $competition): string
    {
        $date = '';

        if ($competition->dateFrom !== null) {
            $date = $competition->dateFrom->format('d.m.Y');

            if ($competition->dateTo !== null) {
                $date .= ' - ' . $competition->dateTo->format('d.m.Y');
            }
        }

        $descriptionParts = [];

        // An edition card carries its series name, so the selected item in the control stays self-descriptive (the
        // edition's own name alone says nothing once the optgroup header is out of sight)
        if ($competition->seriesId !== null && $competition->seriesName !== null) {
            $descriptionParts[] = self::escape($competition->seriesName);
        }

        $location = $this->location($competition->locationCountryCode, $competition->location);

        if ($location !== '') {
            $descriptionParts[] = $location;
        }

        return $this->card($competition->logo, $competition->name, self::escape($date), $competition->eventStatus === 'live', $descriptionParts);
    }

    /**
     * A series: logo, name, its next or last date ("No dates yet" without any), a live badge while an edition is live,
     * "Online" or its place
     */
    private function renderSeriesCard(SelectableCompetition $series): string
    {
        $date = match (true) {
            $series->nextDay !== null => $this->translator->trans('series_picker.next', ['%date%' => $this->dates->format($series->nextDay, 'yMMMd')]),
            $series->lastDay !== null => $this->translator->trans('series_picker.last', ['%date%' => $this->dates->format($series->lastDay, 'yMMMd')]),
            $series->eventStatus === 'live' => '',
            default => $this->translator->trans('series_picker.no_dates'),
        };

        $description = $series->isOnline
            ? self::escape($this->translator->trans('series_picker.online'))
            : $this->location($series->locationCountryCode, $series->location);

        return $this->card($series->logo, $series->name, self::escape($date), $series->eventStatus === 'live', $description !== '' ? [$description] : [], 'sp-series-option');
    }

    /**
     * @param list<string> $descriptionParts already escaped HTML
     */
    private function card(null|string $logo, string $name, string $escapedDate, bool $live, array $descriptionParts, string $extraClass = ''): string
    {
        $img = '';

        if ($logo !== null) {
            $logoUrl = self::escape($this->imageThumbnail->thumbnailUrl($logo, 'puzzle_small'));

            $img = <<<HTML
<img alt="" class="img-fluid rounded-2 competition-option-logo" src="{$logoUrl}" loading="lazy" decoding="async" width="48" height="48">
HTML;
        }

        $liveBadge = '';

        if ($live) {
            $liveBadge = '<span class="badge bg-success ms-1">' . self::escape($this->translator->trans('forms.competition_live_badge')) . '</span>';
        }

        $description = implode(' · ', $descriptionParts);
        $escapedName = self::escape($name);
        $classes = trim('py-1 d-flex low-line-height competition-option ' . $extraClass);

        return <<<HTML
<div class="{$classes}">
    <div class="icon me-2">{$img}</div>
    <div class="pe-1">
        <div class="mb-1">
            <span class="h6">{$escapedName}</span>
            <small class="text-muted">{$escapedDate}</small>{$liveBadge}
        </div>
        <div class="description"><small>{$description}</small></div>
    </div>
</div>
HTML;
    }

    private function location(null|CountryCode $countryCode, null|string $location): string
    {
        $html = '';

        if ($countryCode !== null) {
            $html = '<span class="shadow-custom fi fi-' . $countryCode->name . ' me-2"></span>';
        }

        if ($location !== null) {
            $html .= self::escape($location);
        }

        return $html;
    }

    /**
     * Plain-text search terms TomSelect matches besides the (tag-stripped) card text. A series is found by its
     * organization's names too.
     */
    private function keywords(SelectableCompetition $competition): string
    {
        if ($competition->kind === SelectableCompetition::KIND_SERIES) {
            return self::joinKeywords([
                $competition->name,
                $competition->shortcut,
                $competition->organizationName,
                $competition->organizationShortName,
                $competition->location,
            ]);
        }

        return self::joinKeywords([
            $competition->seriesName,
            $competition->seriesShortcut,
            $competition->name,
            $competition->shortcut,
            $competition->location,
        ]);
    }

    /**
     * @param list<null|string> $parts
     */
    private static function joinKeywords(array $parts): string
    {
        $parts = array_filter($parts, static fn (null|string $part): bool => $part !== null && trim($part) !== '');

        return trim(implode(' ', $parts));
    }

    private static function dateRange(null|DateTimeImmutable $from, null|DateTimeImmutable $to): string
    {
        if ($from === null) {
            return '';
        }

        $date = $from->format('d.m.Y');

        if ($to !== null && $to->format('Y-m-d') !== $from->format('Y-m-d')) {
            $date .= ' - ' . $to->format('d.m.Y');
        }

        return $date;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES);
    }
}
