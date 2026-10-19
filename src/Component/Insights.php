<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Query\GetInsightsPuzzles;
use SpeedPuzzling\Web\Results\Insights\InsightsDrillDownResult;
use SpeedPuzzling\Web\Results\Insights\InsightsPage;
use SpeedPuzzling\Web\Results\Insights\InsightsReport;
use SpeedPuzzling\Web\Results\Insights\InsightsSkillSegment;
use SpeedPuzzling\Web\Results\InsightsPuzzle;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Services\Insights\InsightsCache;
use SpeedPuzzling\Web\Services\Insights\InsightsChartsFactory;
use SpeedPuzzling\Web\Services\Insights\InsightsCriteria;
use SpeedPuzzling\Web\Services\Insights\InsightsFeature;
use SpeedPuzzling\Web\Services\Insights\InsightsPageLoader;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\InsightsImageTrait;
use SpeedPuzzling\Web\Value\InsightsInclusionFilter;
use SpeedPuzzling\Web\Value\InsightsPeriod;
use SpeedPuzzling\Web\Value\InsightsPuzzlePersonality;
use SpeedPuzzling\Web\Value\InsightsResultsFilter;
use SpeedPuzzling\Web\Value\InsightsSkillGroup;
use SpeedPuzzling\Web\Value\InsightsTimeOfDay;
use SpeedPuzzling\Web\Value\PiecesRange;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * The player's own Insights page (docs/features/insights/page.md): the core sections of the first response, built by
 * InsightsPageLoader for the signed-in player's own id only, through the page cache (InsightsCache, keyed by the
 * batch generation, the player's data, the filters, membership, time zone and the predictions setting).
 *
 * The URL is the filter state, like Compare: flat props named like InsightsCriteria::toQueryParameters(), normalized on
 * every render by InsightsCriteria (a free player's members-only values are dropped) and reflected back, so the URL
 * always says what is shown. The period is picked through `when` (one select for the period and a past year) - never a
 * prop hook: Live re-sends the value of every <select data-model> whose option differs from the prop with each request,
 * so a hook would run on every request (the Comparison component's gotcha).
 *
 * Free players never receive members-only content: the template renders a lock with real counts instead
 * (InsightsSection::isFree()). Nothing outlives the request - components are not shared services.
 */
#[AsLiveComponent]
final class Insights
{
    use DefaultActionTrait;

    public const string WHEN_PAST_YEAR_PREFIX = 'year-';

    #[LiveProp(writable: true, url: true)]
    public null|string $period = null;

    #[LiveProp(writable: true, url: true)]
    public null|string $year = null;

    /** Exact dates (members), Y-m-d - typing one switches the period to the exact range */
    #[LiveProp(writable: true, url: true, onUpdated: 'onDateUpdated')]
    public null|string $from = null;

    #[LiveProp(writable: true, url: true, onUpdated: 'onDateUpdated')]
    public null|string $to = null;

    #[LiveProp(writable: true, url: true)]
    public null|string $pieces = null;

    #[LiveProp(writable: true, url: true)]
    public null|string $results = null;

    /** @var list<string> */
    #[LiveProp(writable: true, url: true)]
    public array $brands = [];

    /** @var list<string> */
    #[LiveProp(writable: true, url: true)]
    public array $difficulty = [];

    #[LiveProp(writable: true, url: true)]
    public null|string $events = null;

    #[LiveProp(writable: true, url: true)]
    public null|string $unboxed = null;

    #[LiveProp(writable: true, url: true)]
    public null|string $shelf = null;

    /** @var list<string> */
    #[LiveProp(writable: true, url: true)]
    public array $weekdays = [];

    /** @var list<string> */
    #[LiveProp(writable: true, url: true)]
    public array $personality = [];

    /** @var list<string> */
    #[LiveProp(writable: true, url: true)]
    public array $traits = [];

    /** @var list<string> */
    #[LiveProp(writable: true, url: true)]
    public array $timeOfDay = [];

    /**
     * The period select's value: an InsightsPeriod value, or WHEN_PAST_YEAR_PREFIX + year. Set from the props on every
     * render; a different value coming in is the visitor's pick.
     */
    #[LiveProp(writable: true)]
    public string $when = '';

    /** The open skill-map row (members): "group:key" - not in the URL, a drill-down is not shared */
    #[LiveProp]
    public null|string $drill = null;

    // --- Computed per request in load(), read by the templates ------------------------------------------------------

    public bool $visible = false;

    public bool $isMember = false;

    public bool $canStartFreeTrial = false;

    public null|PlayerProfile $profile = null;

    public null|InsightsReport $report = null;

    /** @var array<string, InsightsPuzzle> */
    public array $puzzles = [];

    public InsightsCriteria $criteria;

    /** A short key of the filter state - chart containers carry it, so a filter change rebuilds a chart, never morphs it */
    public string $stateKey = '';

    /** @var array<string, Chart> */
    public array $charts = [];

    public null|InsightsSkillSegment $drillSegment = null;

    private bool $loaded = false;

    public function __construct(
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly InsightsFeature $insightsFeature,
        private readonly InsightsPageLoader $insightsPageLoader,
        private readonly InsightsCache $insightsCache,
        private readonly GetInsightsPuzzles $getInsightsPuzzles,
        private readonly InsightsChartsFactory $insightsChartsFactory,
        private readonly ClockInterface $clock,
        private readonly TranslatorInterface $translator,
    ) {
        $this->criteria = InsightsCriteria::fromUserInput([], false);
    }

    #[PostMount]
    public function initialize(): void
    {
        $this->load();
    }

    #[PreReRender]
    public function prepare(): void
    {
        if ($this->loaded === false) {
            $this->load();
        }
    }

    public function onDateUpdated(): void
    {
        if ($this->from !== null && $this->from !== '' || $this->to !== null && $this->to !== '') {
            $this->period = InsightsPeriod::Custom->value;
            $this->when = InsightsPeriod::Custom->value;
        }
    }

    /**
     * A skill-map row's drill-down (members): open it, or close it when it is the open one
     */
    #[LiveAction]
    public function toggleDrill(#[LiveArg] string $segment): void
    {
        $this->drill = $this->drill === $segment ? null : $segment;
    }

    /**
     * "Clear filters": the defaults - for a free player the last 6 months and every piece count
     */
    #[LiveAction]
    public function clearFilters(): void
    {
        $this->period = null;
        $this->year = null;
        $this->from = null;
        $this->to = null;
        $this->pieces = null;
        $this->results = null;
        $this->brands = [];
        $this->difficulty = [];
        $this->events = null;
        $this->unboxed = null;
        $this->shelf = null;
        $this->weekdays = [];
        $this->personality = [];
        $this->traits = [];
        $this->timeOfDay = [];
        $this->when = '';
        $this->drill = null;
    }

    // --- Template helpers --------------------------------------------------------------------------------------------

    public function enoughResults(): bool
    {
        return $this->report !== null && $this->report->totalResultCount >= InsightsReport::MIN_RESULTS;
    }

    public function puzzle(null|string $puzzleId): null|InsightsPuzzle
    {
        return $puzzleId !== null ? $this->puzzles[strtolower($puzzleId)] ?? null : null;
    }

    /**
     * The results of the open skill-map row whose puzzle may be shown (a secret puzzle never is)
     *
     * @return list<InsightsDrillDownResult>
     */
    public function getDrillResults(): array
    {
        if ($this->drillSegment === null) {
            return [];
        }

        return array_values(array_filter(
            $this->drillSegment->drillDown,
            fn (InsightsDrillDownResult $result): bool => $this->puzzle($result->puzzleId) !== null,
        ));
    }

    /**
     * The name of a skill-map row: the brand's name, or the translated piece count, tier, situation, personality, trait
     */
    public function segmentLabel(InsightsSkillSegment $segment): string
    {
        if ($segment->label !== null) {
            return $segment->label;
        }

        return match ($segment->group) {
            InsightsSkillGroup::Pieces => $this->translator->trans('insights.skills.pieces', ['%count%' => $segment->key]),
            InsightsSkillGroup::Difficulty => DifficultyTier::tryFrom((int) $segment->key) !== null
                ? $this->translator->trans(DifficultyTier::from((int) $segment->key)->translationKey())
                : $this->translator->trans('insights.skills.unrated'),
            InsightsSkillGroup::ImageTraits => $this->translator->trans(InsightsImageTrait::from($segment->key)->labelKey()),
            InsightsSkillGroup::Brand => $segment->key,
            default => $this->translator->trans('insights.' . $segment->group->value . '.' . $segment->key),
        };
    }

    public function segmentId(InsightsSkillSegment $segment): string
    {
        return $segment->group->value . ':' . $segment->key;
    }

    /**
     * Filters beyond the period and pieces that narrow the selection down, for the badge on "More filters"
     */
    public function getMoreFiltersCount(): int
    {
        $parameters = $this->criteria->toQueryParameters();
        $count = 0;

        foreach (['results', 'brands', 'difficulty', 'events', 'unboxed', 'shelf', 'weekdays', 'personality', 'traits', 'timeOfDay'] as $key) {
            if (isset($parameters[$key])) {
                $count++;
            }
        }

        return $count;
    }

    public function hasActiveFilters(): bool
    {
        return $this->criteria->toQueryParameters() !== [];
    }

    /**
     * The period options: free players the last 3 and 6 months, members everything plus the years they solved in
     *
     * @return list<array{value: string, label: string, parameters: array<string, int|string>}>
     */
    public function getWhenOptions(): array
    {
        $options = [];

        foreach ([InsightsPeriod::Last3Months, InsightsPeriod::Last6Months, InsightsPeriod::Last12Months, InsightsPeriod::Last24Months, InsightsPeriod::ThisYear] as $period) {
            if ($this->isMember || $period->isFree()) {
                $options[] = ['value' => $period->value, 'label' => 'insights.period.' . $period->value, 'parameters' => []];
            }
        }

        if ($this->isMember) {
            $thisYear = (int) $this->clock->now()->format('Y');

            foreach ($this->report?->filterOptions->years ?? [] as $year) {
                if ($year < $thisYear) {
                    $options[] = ['value' => self::WHEN_PAST_YEAR_PREFIX . $year, 'label' => 'insights.period.past_year', 'parameters' => ['%year%' => $year]];
                }
            }

            $options[] = ['value' => InsightsPeriod::All->value, 'label' => 'insights.period.all', 'parameters' => []];
            $options[] = ['value' => InsightsPeriod::Custom->value, 'label' => 'insights.period.custom', 'parameters' => []];
        }

        // The shown period always has its option - a past year without results (or the current year as a past year,
        // from a link) would otherwise show the first option, and Live would write that back into `when`
        if (in_array($this->when, array_column($options, 'value'), true) === false) {
            $options[] = $this->criteria->period === InsightsPeriod::PastYear
                ? ['value' => $this->when, 'label' => 'insights.period.past_year', 'parameters' => ['%year%' => (int) $this->criteria->year]]
                : ['value' => $this->when, 'label' => 'insights.period.' . $this->criteria->period->value, 'parameters' => []];
        }

        return $options;
    }

    /**
     * @return array<int|string, string> URL param => label (PHP turns "500" keys into ints)
     */
    public function getPiecesOptions(): array
    {
        $options = [];

        foreach (PiecesRange::PRESETS as $param => $label) {
            $options[(string) $param] = $label;
        }

        // A custom range from a shared link stays selectable
        $current = $this->criteria->pieces->toParam();

        if ($current !== '' && isset($options[$current]) === false) {
            $options[$current] = $this->criteria->pieces->label();
        }

        return $options;
    }

    /**
     * @return list<array{value: int, tier: null|DifficultyTier}>
     */
    public function getDifficultyOptions(): array
    {
        $options = array_map(
            static fn (DifficultyTier $tier): array => ['value' => $tier->value, 'tier' => $tier],
            DifficultyTier::cases(),
        );
        $options[] = ['value' => InsightsCriteria::UNRATED_DIFFICULTY, 'tier' => null];

        return $options;
    }

    /**
     * @return list<InsightsResultsFilter>
     */
    public function getResultsOptions(): array
    {
        return InsightsResultsFilter::cases();
    }

    /**
     * @return list<InsightsInclusionFilter>
     */
    public function getInclusionOptions(): array
    {
        return InsightsInclusionFilter::cases();
    }

    /**
     * @return list<InsightsPuzzlePersonality>
     */
    public function getPersonalityOptions(): array
    {
        return InsightsPuzzlePersonality::cases();
    }

    /**
     * @return list<InsightsImageTrait>
     */
    public function getTraitOptions(): array
    {
        return InsightsImageTrait::cases();
    }

    /**
     * @return list<InsightsTimeOfDay>
     */
    public function getTimeOfDayOptions(): array
    {
        return InsightsTimeOfDay::cases();
    }

    /**
     * The question chips at the top, in page order: [anchor, translation key, free]. The lazy frames add their own sections'
     * questions here (rating, forecast, re-solve, pairs & teams).
     *
     * @return list<array{anchor: string, key: string, free: bool}>
     */
    public function getQuestions(): array
    {
        return [
            ['anchor' => 'insights-profile', 'key' => 'insights.ask.profile', 'free' => true],
            ['anchor' => 'insights-form', 'key' => 'insights.ask.form', 'free' => true],
            ['anchor' => 'insights-skills', 'key' => 'insights.ask.strengths', 'free' => true],
            ['anchor' => 'insights-skills', 'key' => 'insights.ask.weak_spots', 'free' => true],
            ['anchor' => 'insights-expected', 'key' => 'insights.ask.expected', 'free' => false],
            ['anchor' => 'insights-median', 'key' => 'insights.ask.median', 'free' => true],
            ['anchor' => 'insights-close-calls', 'key' => 'insights.ask.close_calls', 'free' => true],
            // The lazy frames' sections (InsightsLazyPart::anchors() - the placeholders carry the anchors)
            ['anchor' => 'insights-rating', 'key' => 'insights.rating.ask', 'free' => false],
            ['anchor' => 'insights-forecast', 'key' => 'insights.forecast.ask', 'free' => false],
            ['anchor' => 'insights-resolve', 'key' => 'insights.resolve.ask', 'free' => false],
            ['anchor' => 'insights-learning', 'key' => 'insights.ask.learning', 'free' => false],
            ['anchor' => 'insights-pairs', 'key' => 'insights.pairs.ask', 'free' => false],
            ['anchor' => 'insights-habits', 'key' => 'insights.ask.habits', 'free' => true],
            ['anchor' => 'insights-improvement', 'key' => 'insights.improvement.ask', 'free' => false],
        ];
    }

    // --- Loading -----------------------------------------------------------------------------------------------------

    private function load(): void
    {
        $this->loaded = true;
        $this->visible = false;
        $this->report = null;
        $this->puzzles = [];
        $this->charts = [];
        $this->drillSegment = null;

        $profile = $this->retrieveLoggedUserProfile->getProfile();

        // The Live endpoint answers like the page: nothing for anybody InsightsFeature does not show it to
        if ($profile === null || $this->insightsFeature->isVisibleTo($profile) === false) {
            return;
        }

        $this->visible = true;
        $this->profile = $profile;
        $this->isMember = $profile->activeMembership;
        $this->canStartFreeTrial = $profile->canStartFreeTrial();

        $timezone = InsightsPageLoader::zone($profile->timezone) !== null ? $profile->timezone : null;
        $predictionsAllowed = $profile->timePredictionsOptedOut === false;

        $this->applyWhen();
        $this->criteria = InsightsCriteria::fromUserInput($this->query(), $this->isMember, $timezone !== null);
        $this->reflect();

        $criteria = $this->criteria;
        $playerId = $profile->playerId;
        $key = $this->insightsCache->keyFor($playerId);

        /** @var InsightsPage $page */
        $page = $this->insightsCache->get(
            $key,
            'page',
            ['criteria' => $criteria->cacheKey($timezone), 'predictions' => $predictionsAllowed],
            fn (): InsightsPage => $this->insightsPageLoader->load($playerId, $criteria, $timezone, $predictionsAllowed),
        );

        $this->report = $page->report;
        $this->puzzles = $page->puzzles;
        $this->stateKey = substr(md5($criteria->cacheKey($timezone)), 0, 8);

        if ($this->enoughResults()) {
            $this->charts = $this->insightsChartsFactory->charts($page->report, $this->isMember);
        }

        $this->loadDrill();
    }

    /**
     * The open skill-map row and its own hydrate: one statement, only for the puzzles the first response did not read
     */
    private function loadDrill(): void
    {
        if ($this->drill === null || $this->isMember === false || $this->report === null) {
            $this->drill = null;

            return;
        }

        foreach ($this->report->skillMap->groups as $group) {
            foreach ($group->segments as $segment) {
                if ($this->segmentId($segment) === $this->drill) {
                    $this->drillSegment = $segment;
                }
            }
        }

        if ($this->drillSegment === null) {
            $this->drill = null;

            return;
        }

        $missing = [];

        foreach ($this->drillSegment->drillDown as $result) {
            if ($this->puzzle($result->puzzleId) === null) {
                $missing[] = $result->puzzleId;
            }
        }

        if ($missing !== []) {
            $this->puzzles += $this->getInsightsPuzzles->byIds($missing);
        }
    }

    /**
     * A new pick of the period select: a period, or a past year
     */
    private function applyWhen(): void
    {
        if ($this->when === '' || $this->when === $this->currentWhen()) {
            return;
        }

        if (str_starts_with($this->when, self::WHEN_PAST_YEAR_PREFIX)) {
            $this->period = InsightsPeriod::PastYear->value;
            $this->year = substr($this->when, strlen(self::WHEN_PAST_YEAR_PREFIX));
        } else {
            $this->period = $this->when;
            $this->year = null;
        }

        if ($this->period !== InsightsPeriod::Custom->value) {
            $this->from = null;
            $this->to = null;
        }
    }

    private function currentWhen(): string
    {
        if ($this->period === InsightsPeriod::PastYear->value && $this->year !== null) {
            return self::WHEN_PAST_YEAR_PREFIX . $this->year;
        }

        return (string) $this->period;
    }

    /**
     * @return array<string, null|string|list<string>>
     */
    private function query(): array
    {
        return [
            'period' => $this->period,
            'year' => $this->year,
            'from' => $this->from,
            'to' => $this->to,
            'pieces' => $this->pieces,
            'results' => $this->results,
            'brands' => $this->brands,
            'difficulty' => $this->difficulty,
            'events' => $this->events,
            'unboxed' => $this->unboxed,
            'shelf' => $this->shelf,
            'weekdays' => $this->weekdays,
            'personality' => $this->personality,
            'traits' => $this->traits,
            'timeOfDay' => $this->timeOfDay,
        ];
    }

    /**
     * The normalized state back into the props (and so the URL): what is shown is what the URL says
     */
    private function reflect(): void
    {
        $parameters = $this->criteria->toQueryParameters();

        $string = static fn (string $key): null|string => isset($parameters[$key]) && is_string($parameters[$key]) ? $parameters[$key] : null;
        $list = static fn (string $key): array => isset($parameters[$key]) && is_array($parameters[$key]) ? $parameters[$key] : [];

        $this->period = $string('period');
        $this->year = $string('year');
        $this->from = $string('from');
        $this->to = $string('to');
        $this->pieces = $string('pieces');
        $this->results = $string('results');
        $this->brands = $list('brands');
        $this->difficulty = $list('difficulty');
        $this->events = $string('events');
        $this->unboxed = $string('unboxed');
        $this->shelf = $string('shelf');
        $this->weekdays = $list('weekdays');
        $this->personality = $list('personality');
        $this->traits = $list('traits');
        $this->timeOfDay = $list('timeOfDay');

        $this->when = $this->criteria->period === InsightsPeriod::PastYear
            ? self::WHEN_PAST_YEAR_PREFIX . $this->criteria->year
            : $this->criteria->period->value;
    }
}
