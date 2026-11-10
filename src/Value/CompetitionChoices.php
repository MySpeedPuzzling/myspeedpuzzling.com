<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Closure;

/**
 * The "Competition / event" picker payload for TomSelect plus what it accepts
 * (docs/features/events-page/high-frequency-series.md "Validation, submit, prefill").
 *
 * The list is built only when something reads it - in the forms that is rendering, so a valid submit that only redirects
 * never pays for it. accepts() answers without the list, one short statement of the same rule per submitted value:
 * CompetitionChoicesAcceptsTest compares every kind of value with the full list.
 */
final class CompetitionChoices
{
    /**
     * @var null|array{
     *     options: list<array{value: string, text: string, keywords: string, optgroup?: string}>,
     *     optgroups: list<array{value: string, label: string, logo?: string}>,
     *     values: array<string, true>,
     * }
     */
    private null|array $list = null;

    /**
     * @param Closure(): array{options: list<array{value: string, text: string, keywords: string, optgroup?: string}>, optgroups: list<array{value: string, label: string, logo?: string}>, values: array<string, true>} $loadList
     *        Builds the list; `values` = every option value, for O(1) membership checks
     * @param Closure(string): bool $isSelectableOneTimeEvent Whether the list offers this one-time event (publicly visible)
     * @param Closure(string): bool $isSelectableSeries Whether the list offers this series (publicly visible)
     * @param Closure(string): bool $isSelectableEdition Whether an edition id may be picked (publicly visible)
     */
    public function __construct(
        private readonly Closure $loadList,
        private readonly null|CompetitionPick $current,
        private readonly Closure $isSelectableOneTimeEvent,
        private readonly Closure $isSelectableSeries,
        private readonly Closure $isSelectableEdition,
    ) {
    }

    /**
     * Options in display order - value = CompetitionPick::fieldValue(); an edition carries `optgroup` = series id
     *
     * @return list<array{value: string, text: string, keywords: string, optgroup?: string}>
     */
    public function options(): array
    {
        return $this->list()['options'];
    }

    /**
     * One per series with an offered edition; `value` = series id
     *
     * @return list<array{value: string, label: string, logo?: string}>
     */
    public function optgroups(): array
    {
        return $this->list()['optgroups'];
    }

    /**
     * The options for the field's `tom_select_options`: encoded - and so built - when the form is rendered
     */
    public function lazyOptions(): LazyJsonValue
    {
        return new LazyJsonValue(fn (): array => $this->options());
    }

    /**
     * The optgroups for the field's `tom_select_options`, see lazyOptions()
     */
    public function lazyOptgroups(): LazyJsonValue
    {
        return new LazyJsonValue(fn (): array => $this->optgroups());
    }

    /**
     * Whether the picker renders this exact value as an option
     */
    public function offers(string $fieldValue): bool
    {
        return isset($this->list()['values'][$fieldValue]);
    }

    /**
     * Whether a submitted value may be saved: an offered one-time event or series, the current pick, or a publicly
     * visible edition. A bare uuid of an edition - posted by a form an older release rendered (P2) - counts as that
     * edition.
     *
     * The list holds canonical ids only, so another spelling of a one-time event or a series is not on it; an edition
     * has never been looked up in it either.
     */
    public function accepts(CompetitionPick $pick): bool
    {
        if ($this->current !== null && $this->current->id === $pick->id) {
            if ($this->current->equals($pick)) {
                return true;
            }

            if ($pick->kind === CompetitionPickKind::Event && $this->current->kind === CompetitionPickKind::Edition) {
                return true;
            }
        }

        return match ($pick->kind) {
            CompetitionPickKind::Series => $pick->hasCanonicalId() && ($this->isSelectableSeries)($pick->id),
            CompetitionPickKind::Event => ($pick->hasCanonicalId() && ($this->isSelectableOneTimeEvent)($pick->id))
                || ($this->isSelectableEdition)($pick->id),
            CompetitionPickKind::Edition => ($this->isSelectableEdition)($pick->id),
        };
    }

    /**
     * @return array{options: list<array{value: string, text: string, keywords: string, optgroup?: string}>, optgroups: list<array{value: string, label: string, logo?: string}>, values: array<string, true>}
     */
    private function list(): array
    {
        return $this->list ??= ($this->loadList)();
    }
}
