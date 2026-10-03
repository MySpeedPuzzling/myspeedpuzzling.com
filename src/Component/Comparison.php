<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CanNotRemoveYourselfFromComparison;
use SpeedPuzzling\Web\Exceptions\ComparisonLineUpFull;
use SpeedPuzzling\Web\Exceptions\ComparisonSubjectNotAvailable;
use SpeedPuzzling\Web\Exceptions\ComparisonSubjectNotFound;
use SpeedPuzzling\Web\Message\AddComparisonSubject;
use SpeedPuzzling\Web\Message\ChangeComparisonView;
use SpeedPuzzling\Web\Message\RemoveComparisonSubject;
use SpeedPuzzling\Web\Query\GetComparisonLineUp;
use SpeedPuzzling\Web\Query\GetComparisonPuzzles;
use SpeedPuzzling\Web\Query\GetComparisonResults;
use SpeedPuzzling\Web\Query\GetComparisonSubjects;
use SpeedPuzzling\Web\Results\ComparisonLineUpItem;
use SpeedPuzzling\Web\Results\ComparisonPuzzle;
use SpeedPuzzling\Web\Results\ComparisonResult;
use SpeedPuzzling\Web\Results\ComparisonSubject;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Results\PuzzleListInsights;
use SpeedPuzzling\Web\Services\ComparisonBuilder;
use SpeedPuzzling\Web\Services\PuzzleFilterOptions;
use SpeedPuzzling\Web\Services\ResolvePuzzleListInsights;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ComparisonCriteria;
use SpeedPuzzling\Web\Value\ComparisonKind;
use SpeedPuzzling\Web\Value\ComparisonLimits;
use SpeedPuzzling\Web\Value\ComparisonPeriod;
use SpeedPuzzling\Web\Value\ComparisonShow;
use SpeedPuzzling\Web\Value\ComparisonSort;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;
use SpeedPuzzling\Web\Value\ComparisonTimes;
use SpeedPuzzling\Web\Value\ComparisonView;
use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\PiecesRange;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;
use Symfony\UX\LiveComponent\Metadata\UrlMapping;
use Symfony\UX\TwigComponent\Attribute\PostMount;

/**
 * The compare page (docs/features/player-comparison.md): the viewer's persistent line-up of one kind (Solo, Pairs or
 * Teams) - or, with `with`, a shared comparison shown as a preview that writes nothing - its summary, filters and the
 * compared puzzles.
 *
 * The URL is the state: flat scalar props, normalized on every render by ComparisonCriteria (members-only values of a
 * free player are dropped) and reflected back, so the URL always says what is shown. Every render costs the same few
 * statements whatever the line-up: the line-up, its subjects (one statement for every kind), the aggregate, the shown
 * puzzles (+ list insights for members). Everything is computed once per request in load(); components are not shared
 * services, so nothing outlives the request.
 *
 * Actions never throw at the visitor: the domain exceptions of the line-up become a quiet inline notice (a full free
 * line-up opens the swap prompt instead), anonymous `/_components` calls do nothing.
 */
#[AsLiveComponent]
final class Comparison
{
    use DefaultActionTrait;

    public const string TAB_PUZZLES = 'puzzles';

    public const string TAB_CHARTS = 'charts';

    /** The subjects one shared link may carry, every kind together */
    public const int MAX_SHARED_REFS = 30;

    /** Solo, Pairs or Teams - which line-up is compared */
    #[LiveProp(url: true)]
    public null|string $kind = null;

    /** Null = the puzzles list, 'charts' = the members' charts */
    #[LiveProp(url: true)]
    public null|string $tab = null;

    #[LiveProp(url: true)]
    public null|string $show = null;

    #[LiveProp(url: true)]
    public null|string $times = null;

    #[LiveProp(writable: true, url: true, onUpdated: 'onPeriodUpdated')]
    public null|string $period = null;

    /** Custom range (members), Y-m-d - typing a date switches the period to the custom range */
    #[LiveProp(writable: true, url: true, onUpdated: 'onDateUpdated')]
    public null|string $from = null;

    #[LiveProp(writable: true, url: true, onUpdated: 'onDateUpdated')]
    public null|string $to = null;

    /** The custom range is open in the filter sheet (members) - its dates may not be picked yet */
    #[LiveProp]
    public bool $customRange = false;

    /** A PiecesRange param, the single source of truth for the two bounds below (like PuzzleSearch) */
    #[LiveProp(writable: true, url: true, onUpdated: 'onFilterUpdated')]
    public null|string $pieces = null;

    #[LiveProp(writable: true, onUpdated: 'onPiecesBoundsUpdated')]
    public null|int $piecesMin = null;

    #[LiveProp(writable: true, onUpdated: 'onPiecesBoundsUpdated')]
    public null|int $piecesMax = null;

    /**
     * Manufacturer ids (members)
     *
     * @var list<string>
     */
    #[LiveProp(writable: true, url: true, onUpdated: 'onFilterUpdated')]
    public array $brands = [];

    /**
     * Difficulty tiers (members) as strings, like the URL carries them; 0 = not rated yet
     *
     * @var list<string>
     */
    #[LiveProp(url: true)]
    public array $difficulty = [];

    #[LiveProp(writable: true, url: true, onUpdated: 'onFilterUpdated')]
    public null|string $sort = null;

    /** The highlighted pair (ComparisonSubjectRef strings) - set for 3+ subjects, where the picker shows it */
    #[LiveProp(writable: true, url: new UrlMapping(as: 'a'), onUpdated: 'onFilterUpdated')]
    public null|string $highlightA = null;

    #[LiveProp(writable: true, url: new UrlMapping(as: 'b'), onUpdated: 'onFilterUpdated')]
    public null|string $highlightB = null;

    /** Preview of a shared comparison: comma separated refs - nothing is written until "Add to my line-up" */
    #[LiveProp(url: true)]
    public null|string $with = null;

    /** A ref the free line-up had no room for (the entry points redirect with it): the swap prompt */
    #[LiveProp(url: true)]
    public null|string $swap = null;

    /** "Show more" adds a page; never in the URL - paging is not shared */
    #[LiveProp]
    public int $limit = ComparisonCriteria::PAGE_SIZE;

    /** Cards / Table / Duel for 3+ subjects, remembered on the player (ChangeComparisonView) */
    #[LiveProp]
    public string $view = 'cards';

    // --- Computed per request in load(), read by the templates ------------------------------------------------------

    public bool $signedIn = false;

    public bool $isMember = false;

    public bool $isPreview = false;

    public ComparisonKind $activeKind = ComparisonKind::Solo;

    /** @var array<string, int> kind value => subjects in that line-up */
    public array $kindCounts = ['solo' => 0, 'pairs' => 0, 'teams' => 0];

    /**
     * The line-up strip in line-up order: what is shown, the row to remove (null for the implicit "you" and in a
     * preview) and whether it may be removed.
     *
     * @var list<array{subject: ComparisonSubject, rowId: null|string, removable: bool}>
     */
    public array $chips = [];

    /** Subjects of a line-up over the free cap (a membership ended) that are not compared - the locked chip */
    public int $lockedCount = 0;

    /** "n / cap" */
    public int $lineUpCount = 0;

    public int $cap = ComparisonLimits::FREE;

    /**
     * The compared subjects, available only, in line-up order
     *
     * @var list<ComparisonSubject>
     */
    public array $compared = [];

    public null|ComparisonResult $result = null;

    public ComparisonCriteria $criteria;

    /** @var array<string, ComparisonPuzzle> */
    public array $puzzles = [];

    public null|PuzzleListInsights $insights = null;

    public null|ComparisonSubject $swapSubject = null;

    /**
     * Rows the swap prompt offers to make room
     *
     * @var list<array{rowId: string, subject: ComparisonSubject}>
     */
    public array $swapCandidates = [];

    /**
     * Refs already in the shown line-up - the add sheet does not offer them
     *
     * @var list<string>
     */
    public array $lineUpRefs = [];

    /**
     * Players the "someone at your speed" pick leaves out: the Solo line-up and the viewer
     *
     * @var list<string>
     */
    public array $excludedPlayerIds = [];

    /** Changes whenever the shown line-up changes - the add sheet closes on it */
    public string $lineUpRevision = '';

    /** Relative URL of exactly what is shown (props normalized) */
    public string $pageUrl = '';

    /** Absolute link to this comparison for somebody else: the shown subjects + filters */
    public string $shareUrl = '';

    /** A one-off message of the action that ran in this request (translation key + parameters) */
    public null|string $notice = null;

    /** @var array<string, int|string> */
    public array $noticeParameters = [];

    /** @var array<string, array{name: string, logo: null|string}> selected brand id => label */
    public array $selectedBrands = [];

    private bool $loaded = false;

    private null|string $viewerId = null;

    /** @var array<string, ComparisonSubject> */
    private array $subjectsByRef = [];

    /** @var array<string, string> */
    private array $labels = [];

    public function __construct(
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly GetComparisonLineUp $getComparisonLineUp,
        private readonly GetComparisonSubjects $getComparisonSubjects,
        private readonly GetComparisonResults $getComparisonResults,
        private readonly GetComparisonPuzzles $getComparisonPuzzles,
        private readonly ComparisonBuilder $comparisonBuilder,
        private readonly ResolvePuzzleListInsights $resolvePuzzleListInsights,
        private readonly PuzzleFilterOptions $puzzleFilterOptions,
        private readonly MessageBusInterface $messageBus,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
    ) {
        $this->criteria = ComparisonCriteria::fromUserInput(0, false);
    }

    #[PostMount]
    public function initialize(): void
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile !== null) {
            $this->view = $profile->comparisonView->value;
        }

        $this->load();
    }

    #[PreReRender]
    public function prepare(): void
    {
        if ($this->loaded === false) {
            $this->load();
        }
    }

    // --- Prop hooks --------------------------------------------------------------------------------------------------

    public function onFilterUpdated(): void
    {
        $this->limit = ComparisonCriteria::PAGE_SIZE;
    }

    /**
     * The quick select of the period: a preset closes the custom range
     */
    public function onPeriodUpdated(): void
    {
        $this->customRange = $this->period === ComparisonPeriod::Custom->value;
        $this->onFilterUpdated();
    }

    public function onDateUpdated(): void
    {
        if ($this->from !== null || $this->to !== null) {
            $this->period = ComparisonPeriod::Custom->value;
        }

        $this->onFilterUpdated();
    }

    public function onPiecesBoundsUpdated(): void
    {
        $this->pieces = PiecesRange::fromBounds($this->piecesMin, $this->piecesMax)?->toParam();
        $this->onFilterUpdated();
    }

    // --- Actions -----------------------------------------------------------------------------------------------------

    #[LiveAction]
    public function changeKind(#[LiveArg] string $kind): void
    {
        $value = ComparisonKind::tryFrom($kind);

        if ($value === null) {
            return;
        }

        $this->kind = $value->value;
        $this->highlightA = null;
        $this->highlightB = null;
        $this->swap = null;
        $this->limit = ComparisonCriteria::PAGE_SIZE;
    }

    #[LiveAction]
    public function changeTab(#[LiveArg] string $tab): void
    {
        $this->tab = $tab === self::TAB_CHARTS ? self::TAB_CHARTS : null;
    }

    /**
     * The view switch of 3+ subjects - remembered on the player for every comparison
     */
    #[LiveAction]
    public function changeView(#[LiveArg] string $view): void
    {
        $value = ComparisonView::tryFrom($view);

        if ($value === null) {
            return;
        }

        $this->view = $value->value;
        // The Duel view lists other puzzles than Cards and Table: back to the first page
        $this->limit = ComparisonCriteria::PAGE_SIZE;
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile !== null && $profile->comparisonView !== $value) {
            $this->messageBus->dispatch(new ChangeComparisonView($profile->playerId, $value));
        }
    }

    #[LiveAction]
    public function chooseShow(#[LiveArg] string $value): void
    {
        $this->show = ComparisonShow::tryFrom($value)?->value;
        $this->onFilterUpdated();
    }

    /**
     * "Show all puzzles" of the empty state - one tap, never a silent switch
     */
    #[LiveAction]
    public function showAll(): void
    {
        $this->chooseShow(ComparisonShow::All->value);
    }

    #[LiveAction]
    public function chooseTimes(#[LiveArg] string $value): void
    {
        $this->times = ComparisonTimes::tryFrom($value)?->value;
        $this->onFilterUpdated();
    }

    #[LiveAction]
    public function toggleFirstTries(): void
    {
        $this->chooseTimes($this->times === ComparisonTimes::FirstTries->value ? ComparisonTimes::Best->value : ComparisonTimes::FirstTries->value);
    }

    #[LiveAction]
    public function choosePeriod(#[LiveArg] string $value): void
    {
        $period = ComparisonPeriod::tryFrom($value);
        $this->period = $period?->value;
        $this->customRange = $period === ComparisonPeriod::Custom;

        if ($period !== ComparisonPeriod::Custom) {
            $this->from = null;
            $this->to = null;
        }

        $this->onFilterUpdated();
    }

    #[LiveAction]
    public function chooseSort(#[LiveArg] string $value): void
    {
        $this->sort = ComparisonSort::tryFrom($value)?->value;
        $this->onFilterUpdated();
    }

    #[LiveAction]
    public function toggleDifficulty(#[LiveArg] string $tier): void
    {
        if (preg_match('/^\d$/', $tier) !== 1) {
            return;
        }

        $tiers = array_values(array_filter($this->difficulty, static fn (string $selected): bool => $selected !== $tier));

        if (count($tiers) === count($this->difficulty)) {
            $tiers[] = $tier;
        }

        $this->difficulty = $tiers;
        $this->onFilterUpdated();
    }

    #[LiveAction]
    public function showMore(): void
    {
        $this->limit = min(ComparisonCriteria::MAX_LIMIT, $this->limit + ComparisonCriteria::PAGE_SIZE);
    }

    /**
     * Puts a player or pair/team into the viewer's line-up (the add sheet). A free line-up at its cap opens the swap
     * prompt for it; with `replaceRowId` (the prompt's answer) that row makes room.
     */
    #[LiveAction]
    public function add(#[LiveArg] string $ref, #[LiveArg] null|string $replaceRowId = null): void
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();
        $subjectRef = ComparisonSubjectRef::tryFromString($ref);

        if ($profile === null || $subjectRef === null) {
            return;
        }

        if ($replaceRowId !== null && Uuid::isValid($replaceRowId) === false) {
            $replaceRowId = null;
        }

        $outcome = $this->dispatchAdd($profile->playerId, $subjectRef, $replaceRowId);

        if ($outcome === 'full' && $profile->activeMembership === false) {
            // Never a dead end: offer the swap
            $this->swap = $subjectRef->toString();

            return;
        }

        $this->swap = null;
        $this->setOutcomeNotice($outcome, $profile->activeMembership);
    }

    /**
     * The "someone at your speed" pick (ComparisonSimilarSpeed emits it up)
     */
    #[LiveListener('comparisonAddSubject')]
    public function addSubject(#[LiveArg] string $ref): void
    {
        $this->add($ref);
    }

    #[LiveAction]
    public function remove(#[LiveArg] string $rowId): void
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null || Uuid::isValid($rowId) === false) {
            return;
        }

        try {
            $this->messageBus->dispatch(new RemoveComparisonSubject($profile->playerId, $rowId));
            $this->limit = ComparisonCriteria::PAGE_SIZE;
        } catch (CanNotRemoveYourselfFromComparison) {
            $this->notice = 'comparison.notice.cannot_remove_self';
        } catch (ComparisonSubjectNotFound) {
            // Already gone (another tab) - the re-render shows the line-up as it is
        }
    }

    #[LiveAction]
    public function dismissSwap(): void
    {
        $this->swap = null;
    }

    /**
     * "Add to my line-up" of a shared comparison: the shown subjects go in, in their order, until the line-up is full.
     */
    #[LiveAction]
    public function addPreview(): void
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return;
        }

        $this->load();

        if ($this->isPreview === false) {
            return;
        }

        $existing = [];

        foreach ($this->getComparisonLineUp->forPlayer($profile->playerId) as $item) {
            $existing[$item->ref->toString()] = true;
        }

        $added = 0;
        $full = false;
        $swapCandidate = null;

        foreach ($this->compared as $subject) {
            $key = $subject->ref->toString();

            // You come with the first Solo subject; whoever is in already stays where they are
            if ($subject->isViewer || isset($existing[$key])) {
                continue;
            }

            $outcome = $this->dispatchAdd($profile->playerId, $subject->ref, null);

            if ($outcome === 'added') {
                $added++;

                continue;
            }

            if ($outcome === 'full') {
                $full = true;
                $swapCandidate = $key;

                break;
            }

            if ($outcome === 'already_added') {
                // A double submit: the transaction is gone, so is this run
                break;
            }
        }

        $this->kind = $this->activeKind->value;
        $this->with = null;
        $this->highlightA = null;
        $this->highlightB = null;
        $this->limit = ComparisonCriteria::PAGE_SIZE;

        if ($full && $profile->activeMembership === false && $swapCandidate !== null) {
            $this->swap = $swapCandidate;
        }

        $this->notice = match (true) {
            $full && $added > 0 => 'comparison.notice.preview_added_full',
            $full => 'comparison.notice.preview_full',
            $added > 0 => 'comparison.notice.preview_added',
            default => 'comparison.notice.preview_nothing_new',
        };
        $this->noticeParameters = ['%count%' => $added];

        // Render the viewer's own line-up now
        $this->loaded = false;
    }

    // --- Template helpers --------------------------------------------------------------------------------------------

    public function labelOf(ComparisonSubjectRef $ref): string
    {
        return $this->labels[$ref->toString()] ?? $this->translator->trans('comparison.unavailable');
    }

    public function subjectOf(ComparisonSubjectRef $ref): null|ComparisonSubject
    {
        return $this->subjectsByRef[$ref->toString()] ?? null;
    }

    /**
     * 'a' (coral) / 'b' (indigo) for the highlighted pair, null for everybody else
     */
    public function roleOf(ComparisonSubjectRef $ref): null|string
    {
        // Nobody is highlighted while there is nothing to compare
        if ($this->result === null || count($this->result->subjects) < 2) {
            return null;
        }

        if ($this->result->highlightA?->equals($ref) === true) {
            return 'a';
        }

        if ($this->result->highlightB?->equals($ref) === true) {
            return 'b';
        }

        return null;
    }

    public function isSelf(ComparisonSubjectRef $ref): bool
    {
        return $this->result?->self?->equals($ref) === true;
    }

    public function isCharts(): bool
    {
        return $this->tab === self::TAB_CHARTS;
    }

    /**
     * Duel rows (exactly two subjects) or the remembered view (3+)
     */
    public function getListView(): string
    {
        if ($this->result === null || count($this->result->subjects) <= 2) {
            return ComparisonView::Duel->value;
        }

        return $this->view;
    }

    /**
     * Filters that narrow the list down, for the badge on the Filters button (the sort is not one)
     */
    public function getActiveFiltersCount(): int
    {
        $parameters = $this->criteria->toQueryParameters();
        $count = 0;

        foreach (['show', 'times', 'period', 'pieces', 'brands', 'difficulty'] as $key) {
            if (isset($parameters[$key])) {
                $count++;
            }
        }

        return $count;
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
        $options[] = ['value' => ComparisonCriteria::UNRATED_DIFFICULTY, 'tier' => null];

        return $options;
    }

    // --- Loading -----------------------------------------------------------------------------------------------------

    private function load(): void
    {
        $this->loaded = true;
        $this->resetComputed();
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return;
        }

        $this->signedIn = true;
        $this->viewerId = $profile->playerId;
        $this->isMember = $profile->activeMembership;
        $this->cap = ComparisonLimits::forMembership($this->isMember);
        $this->tab = $this->tab === self::TAB_CHARTS ? self::TAB_CHARTS : null;
        $this->view = (ComparisonView::tryFrom($this->view) ?? ComparisonView::Cards)->value;
        $this->customRange = $this->customRange && $this->isMember;

        $viewerRef = ComparisonSubjectRef::player($profile->playerId);
        $withRefs = $this->with !== null ? ComparisonSubjectRef::listFromString($this->with, self::MAX_SHARED_REFS) : [];

        if ($withRefs === [] || $this->loadPreview($withRefs, $viewerRef) === false) {
            $this->with = null;
            $this->loadLineUp($viewerRef);
        }

        $this->loadComparison($profile);
    }

    /**
     * A shared link: exactly those subjects, as this viewer may see them, within this viewer's cap - nothing written.
     *
     * @param list<ComparisonSubjectRef> $withRefs
     * @return bool false when nothing of it is available to this viewer (the own line-up is shown instead)
     */
    private function loadPreview(array $withRefs, ComparisonSubjectRef $viewerRef): bool
    {
        $this->rememberSubjects($this->getComparisonSubjects->byRefs([...$withRefs, $viewerRef], $this->viewerId));

        $available = [];

        foreach ($withRefs as $ref) {
            $subject = $this->subjectsByRef[$ref->toString()] ?? null;

            if ($subject !== null && $subject->isAvailable && $subject->kind !== null) {
                $available[] = $subject;
            }
        }

        if ($available === []) {
            return false;
        }

        $this->isPreview = true;
        $this->swap = null;
        // Normalized: what this viewer may not see does not stay in the URL either
        $this->with = implode(',', array_map(static fn (ComparisonSubject $subject): string => $subject->ref->toString(), $available));

        foreach ($available as $subject) {
            assert($subject->kind !== null);
            $this->kindCounts[$subject->kind->value]++;
        }

        $kind = ComparisonKind::tryFrom((string) $this->kind);

        if ($kind === null || $this->kindCounts[$kind->value] === 0) {
            $kind = $available[0]->kind;
            assert($kind !== null);
        }

        $this->activeKind = $kind;
        $ofKind = array_values(array_filter($available, static fn (ComparisonSubject $subject): bool => $subject->kind === $kind));

        if ($this->isMember === false && $kind === ComparisonKind::Solo) {
            // Free Solo is "you + 1 other", whoever shared it
            $viewer = $this->subjectsByRef[$viewerRef->toString()] ?? null;
            $others = array_values(array_filter($ofKind, static fn (ComparisonSubject $subject): bool => $subject->isViewer === false));
            $shown = array_values(array_filter([$viewer, $others[0] ?? null]));
            $this->lockedCount = max(0, count($others) - 1);
        } else {
            $shown = array_slice($ofKind, 0, $this->cap);
            $this->lockedCount = count($ofKind) - count($shown);
        }

        foreach ($shown as $subject) {
            $this->chips[] = ['subject' => $subject, 'rowId' => null, 'removable' => false];
        }

        $this->compared = $shown;
        $this->lineUpCount = count($shown);

        return true;
    }

    /**
     * The viewer's own line-up of the active kind, as they may see it today
     */
    private function loadLineUp(ComparisonSubjectRef $viewerRef): void
    {
        $items = $this->getComparisonLineUp->forPlayer((string) $this->viewerId);
        $swapRef = $this->swap !== null ? ComparisonSubjectRef::tryFromString($this->swap) : null;

        $refs = array_map(static fn (ComparisonLineUpItem $item): ComparisonSubjectRef => $item->ref, $items);
        $refs[] = $viewerRef;

        if ($swapRef !== null) {
            $refs[] = $swapRef;
        }

        // Every kind in one statement - the kind switch, the strip and the swap prompt
        $this->rememberSubjects($this->getComparisonSubjects->byRefs($refs, $this->viewerId));

        $newestKind = null;
        $itemsByKind = ['solo' => [], 'pairs' => [], 'teams' => []];

        foreach ($items as $item) {
            $itemsByKind[$item->kind->value][] = $item;
            $newestKind = $item->kind;
        }

        foreach ($itemsByKind as $kindValue => $kindItems) {
            $this->kindCounts[$kindValue] = count($kindItems);
        }

        // You are in Solo from the start - stored with the first one you add
        if ($this->kindCounts['solo'] === 0) {
            $this->kindCounts['solo'] = 1;
        }

        $this->swapSubject = $this->resolveSwapSubject($swapRef, $items);
        $kind = $this->swapSubject->kind
            ?? ComparisonKind::tryFrom((string) $this->kind)
            ?? $newestKind
            ?? ComparisonKind::Solo;
        $this->activeKind = $kind;

        /** @var list<ComparisonLineUpItem> $kindItems */
        $kindItems = $itemsByKind[$kind->value];

        if ($this->swapSubject !== null) {
            foreach ($kindItems as $item) {
                if ($item->isSelf && $this->isMember === false) {
                    continue;
                }

                $this->swapCandidates[] = ['rowId' => $item->rowId, 'subject' => $this->subjectFor($item->ref, $kind)];
            }
        }

        if ($kind === ComparisonKind::Solo && $kindItems === []) {
            $viewer = $this->subjectFor($viewerRef, $kind);
            $this->chips[] = ['subject' => $viewer, 'rowId' => null, 'removable' => false];
            $this->compared = [$viewer];
            $this->lineUpCount = 1;
            $this->lineUpRefs = [$viewerRef->toString()];
            $this->excludedPlayerIds = [$viewerRef->id];
            $this->lineUpRevision = md5($viewerRef->toString());

            return;
        }

        $comparedKeys = $this->comparedRows($kindItems, $kind);

        foreach ($kindItems as $item) {
            $subject = $this->subjectFor($item->ref, $kind);
            $key = $item->ref->toString();

            if ($subject->isAvailable && isset($comparedKeys[$key]) === false) {
                // Over the free cap: in the locked chip, not compared, not shown
                continue;
            }

            $this->chips[] = [
                'subject' => $subject,
                'rowId' => $item->rowId,
                'removable' => $item->isSelf === false || $this->isMember,
            ];

            if ($subject->isAvailable) {
                $this->compared[] = $subject;
                $this->lineUpRefs[] = $key;
            }
        }

        $this->lockedCount = count(array_filter(
            $kindItems,
            fn (ComparisonLineUpItem $item): bool => $this->subjectFor($item->ref, $kind)->isAvailable && isset($comparedKeys[$item->ref->toString()]) === false,
        ));
        $this->lineUpCount = count($kindItems);

        if ($kind === ComparisonKind::Solo && in_array($viewerRef->toString(), $this->lineUpRefs, true) === false) {
            $this->lineUpRefs[] = $viewerRef->toString();
        }

        foreach ($itemsByKind['solo'] as $item) {
            if ($this->subjectFor($item->ref, ComparisonKind::Solo)->isAvailable) {
                $this->excludedPlayerIds[] = $item->ref->id;
            }
        }

        if (in_array($viewerRef->id, $this->excludedPlayerIds, true) === false) {
            $this->excludedPlayerIds[] = $viewerRef->id;
        }

        $this->lineUpRevision = md5(implode(',', array_map(static fn (ComparisonLineUpItem $item): string => $item->rowId, $kindItems)));
    }

    /**
     * Which available rows are compared: all of them, unless a free line-up holds more than the cap (a membership
     * ended) - then Solo compares you + the most recently added other, Pairs/Teams the most recent two.
     *
     * @param list<ComparisonLineUpItem> $kindItems
     * @return array<string, true> ref strings
     */
    private function comparedRows(array $kindItems, ComparisonKind $kind): array
    {
        $available = array_values(array_filter(
            $kindItems,
            fn (ComparisonLineUpItem $item): bool => $this->subjectFor($item->ref, $kind)->isAvailable,
        ));

        $keys = [];

        if ($this->isMember || count($available) <= $this->cap) {
            foreach ($available as $item) {
                $keys[$item->ref->toString()] = true;
            }

            return $keys;
        }

        $room = $this->cap;

        foreach ($available as $item) {
            if ($item->isSelf) {
                $keys[$item->ref->toString()] = true;
                $room--;
            }
        }

        foreach (array_reverse($available) as $item) {
            if ($room <= 0) {
                break;
            }

            if ($item->isSelf === false) {
                $keys[$item->ref->toString()] = true;
                $room--;
            }
        }

        return $keys;
    }

    /**
     * @param list<ComparisonLineUpItem> $items
     */
    private function resolveSwapSubject(null|ComparisonSubjectRef $swapRef, array $items): null|ComparisonSubject
    {
        if ($swapRef === null) {
            $this->swap = null;

            return null;
        }

        $subject = $this->subjectsByRef[$swapRef->toString()] ?? null;
        $alreadyIn = array_filter($items, static fn (ComparisonLineUpItem $item): bool => $item->ref->equals($swapRef)) !== [];

        if ($subject === null || $subject->isAvailable === false || $subject->kind === null || $alreadyIn || $subject->isViewer) {
            $this->swap = null;

            return null;
        }

        $this->swap = $swapRef->toString();

        return $subject;
    }

    private function loadComparison(PlayerProfile $profile): void
    {
        $refs = array_map(static fn (ComparisonSubject $subject): ComparisonSubjectRef => $subject->ref, $this->compared);

        $this->criteria = ComparisonCriteria::fromUserInput(
            subjectCount: count($refs),
            isMember: $this->isMember,
            show: $this->show,
            times: $this->times,
            period: $this->period,
            from: $this->from,
            to: $this->to,
            pieces: $this->pieces,
            brands: $this->brands,
            difficulty: $this->difficulty,
            sort: $this->sort,
            highlightA: $this->highlightA,
            highlightB: $this->highlightB,
            offset: 0,
            limit: $this->limit,
        );

        $withNames = $this->criteria->needsPuzzleNames() || ($this->isCharts() && $this->isMember);
        $rows = count($refs) >= 2
            ? $this->getComparisonResults->forSubjects($this->activeKind, $refs, $this->criteria, $withNames)
            : [];

        // The Duel view of 3+ subjects lists only what the highlighted pair both solved (the builder ignores it for two)
        $result = $this->comparisonBuilder->build(
            $this->compared,
            $rows,
            $this->criteria,
            highlightedPairOnly: $this->isCharts() === false && $this->view === ComparisonView::Duel->value,
        );
        $this->result = $result;
        $this->reflectCriteria();

        if ($this->isCharts() === false && $result->pagePuzzleIds !== []) {
            $this->puzzles = $this->getComparisonPuzzles->byIds($result->pagePuzzleIds);

            if ($this->isMember) {
                $this->insights = $this->resolvePuzzleListInsights->forViewer($profile, $result->pagePuzzleIds);
            }
        }

        if ($this->criteria->brandIds !== []) {
            $this->selectedBrands = $this->brandLabels($this->criteria->brandIds);
        }

        $this->pageUrl = $this->urlGenerator->generate('comparison', $this->urlParameters(forShare: false));
        $this->shareUrl = $this->urlGenerator->generate('comparison', $this->urlParameters(forShare: true), UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * Writes the applied values back into the props - only what differs from the defaults, so the URL stays short
     */
    private function reflectCriteria(): void
    {
        $parameters = $this->criteria->toQueryParameters();

        $this->kind = $this->activeKind->value;
        $this->show = self::stringParameter($parameters, 'show');
        $this->times = self::stringParameter($parameters, 'times');
        $this->period = self::stringParameter($parameters, 'period');
        $this->from = self::stringParameter($parameters, 'from');
        $this->to = self::stringParameter($parameters, 'to');
        $this->pieces = self::stringParameter($parameters, 'pieces');
        $this->piecesMin = $this->criteria->pieces->minPieces;
        $this->piecesMax = $this->criteria->pieces->maxPieces;
        $this->brands = $this->criteria->brandIds;
        $this->difficulty = array_map(strval(...), $this->criteria->difficultyTiers);
        $this->sort = self::stringParameter($parameters, 'sort');
        $this->limit = $this->criteria->limit;

        // The picker shows the pair for 3+ subjects; with two there is nothing to pick
        $result = $this->result;
        assert($result !== null);
        $pickable = count($result->subjects) >= 3;
        $this->highlightA = $pickable ? $result->highlightA?->toString() : null;
        $this->highlightB = $pickable ? $result->highlightB?->toString() : null;
    }

    /**
     * @return array<string, string|list<string>>
     */
    private function urlParameters(bool $forShare): array
    {
        $parameters = ['kind' => $this->activeKind->value];

        if ($forShare) {
            $parameters['with'] = implode(',', array_map(static fn (ComparisonSubject $subject): string => $subject->ref->toString(), $this->compared));
        } elseif ($this->with !== null) {
            $parameters['with'] = $this->with;
        }

        if ($this->tab !== null) {
            $parameters['tab'] = $this->tab;
        }

        $criteria = $this->criteria->toQueryParameters();
        // The highlighted pair as the props hold it (picked for 3+ subjects only), under its short URL names
        unset($criteria['highlightA'], $criteria['highlightB']);
        $parameters = [...$parameters, ...$criteria];

        if ($this->highlightA !== null) {
            $parameters['a'] = $this->highlightA;
        }

        if ($this->highlightB !== null) {
            $parameters['b'] = $this->highlightB;
        }

        if ($forShare === false && $this->swap !== null) {
            $parameters['swap'] = $this->swap;
        }

        return $parameters;
    }

    /**
     * @param list<ComparisonSubject> $subjects
     */
    private function rememberSubjects(array $subjects): void
    {
        foreach ($subjects as $subject) {
            $key = $subject->ref->toString();
            $this->subjectsByRef[$key] = $subject;
            $this->labels[$key] = $this->label($subject);
        }
    }

    private function subjectFor(ComparisonSubjectRef $ref, ComparisonKind $kind): ComparisonSubject
    {
        return $this->subjectsByRef[$ref->toString()] ?? ComparisonSubject::unavailable($ref, $kind);
    }

    private function label(ComparisonSubject $subject): string
    {
        if ($subject->isAvailable === false) {
            return $this->translator->trans('comparison.unavailable');
        }

        if ($subject->isTeam() === false) {
            if ($subject->isViewer) {
                return $this->translator->trans('comparison.you');
            }

            return $subject->playerName ?? '#' . $subject->playerCode;
        }

        if ($subject->teamName !== null && trim($subject->teamName) !== '') {
            return $subject->teamName;
        }

        $names = [];

        foreach ($subject->members as $member) {
            $names[] = match (true) {
                $member->playerId !== null && $member->playerId === $this->viewerId => $this->translator->trans('comparison.you'),
                $member->isPrivate => $this->translator->trans('secret_puzzler_name'),
                default => $member->playerName ?? $member->guestName ?? '#' . $member->playerCode,
            };
        }

        return implode($subject->teamSize === 2 ? ' & ' : ', ', $names);
    }

    /**
     * @param list<string> $brandIds
     * @return array<string, array{name: string, logo: null|string}>
     */
    private function brandLabels(array $brandIds): array
    {
        $wanted = array_flip($brandIds);
        $labels = [];

        foreach ($this->puzzleFilterOptions->all()['manufacturers'] as $option) {
            if (isset($wanted[$option['value']])) {
                $labels[$option['value']] = ['name' => $option['text'], 'logo' => $option['logo'] ?? null];
            }
        }

        foreach ($brandIds as $brandId) {
            $labels[$brandId] ??= ['name' => $brandId, 'logo' => null];
        }

        return $labels;
    }

    /**
     * @return 'added'|'already_added'|'full'|'not_available'|'not_found'|'self'
     */
    private function dispatchAdd(string $playerId, ComparisonSubjectRef $ref, null|string $replaceRowId): string
    {
        try {
            $this->messageBus->dispatch(new AddComparisonSubject($playerId, $ref->toString(), $replaceRowId));

            return 'added';
        } catch (ComparisonLineUpFull) {
            return 'full';
        } catch (ComparisonSubjectNotAvailable) {
            return 'not_available';
        } catch (ComparisonSubjectNotFound) {
            return 'not_found';
        } catch (CanNotRemoveYourselfFromComparison) {
            return 'self';
        } catch (HandlerFailedException $exception) {
            // Two taps racing each other: the other one added it
            if ($exception->getWrappedExceptions(UniqueConstraintViolationException::class, true) !== []) {
                return 'already_added';
            }

            throw $exception;
        }
    }

    private function setOutcomeNotice(string $outcome, bool $isMember): void
    {
        $this->notice = match ($outcome) {
            'full' => 'comparison.notice.full',
            'not_available' => 'comparison.notice.not_available',
            'not_found' => 'comparison.notice.not_found',
            'self' => 'comparison.notice.cannot_remove_self',
            default => null,
        };
        $this->noticeParameters = ['%cap%' => ComparisonLimits::forMembership($isMember)];
    }

    private function resetComputed(): void
    {
        $this->signedIn = false;
        $this->isPreview = false;
        $this->kindCounts = ['solo' => 0, 'pairs' => 0, 'teams' => 0];
        $this->chips = [];
        $this->lockedCount = 0;
        $this->lineUpCount = 0;
        $this->compared = [];
        $this->result = null;
        $this->puzzles = [];
        $this->insights = null;
        $this->swapSubject = null;
        $this->swapCandidates = [];
        $this->lineUpRefs = [];
        $this->excludedPlayerIds = [];
        $this->lineUpRevision = '';
        $this->selectedBrands = [];
        $this->subjectsByRef = [];
        $this->labels = [];
    }

    /**
     * @param array<string, string|list<string>> $parameters
     */
    private static function stringParameter(array $parameters, string $key): null|string
    {
        $value = $parameters[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
