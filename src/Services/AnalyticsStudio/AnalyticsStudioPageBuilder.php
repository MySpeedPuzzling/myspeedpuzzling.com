<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\AnalyticsStudio;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\AnalyticsStudioNotFound;
use SpeedPuzzling\Web\Query\GetAnalyticsStudioRound;
use SpeedPuzzling\Web\Results\AnalyticsStudioPage;
use SpeedPuzzling\Web\Value\RoundClock;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The analytics studio's page (docs/features/competitions-management/analytics-studio.md §1.1, S3): one statement -
 * GetAnalyticsStudioRound::bySlug() (the round, the event's slugged rounds for the switcher, the public-visibility
 * condition). A round of an event that is not public, a missing round or a round without a slug is
 * AnalyticsStudioNotFound - for the event's team too. The page carries no slot, entrant or result.
 */
readonly final class AnalyticsStudioPageBuilder
{
    /**
     * Every label the browser modules draw with - assets/analytics-studio/render.js LABEL_NAMES, the same list in the
     * same order (AnalyticsStudioPageTest pins both against each other and the English catalogue). Translation key:
     * `analytics_studio.<name>`.
     */
    public const array LABEL_NAMES = [
        'search_placeholder', 'sort', 'sort.name', 'sort.table', 'sort.solves', 'sort.best', 'sort.rating', 'sort.rank',
        'filter.all', 'filter.solved', 'filter.never', 'filter.nohistory',
        'tab.entrants', 'tab.puzzle', 'tab.live',
        'clock.idle', 'clock.scheduled', 'clock.running', 'clock.times_up', 'clock.stopped', 'clock.left',
        'live.count', 'official.count', 'slot.hidden', 'slot.reveals_in', 'slot.image_later',
        'compare', 'compare.clear', 'compare.full', 'compare.add', 'compare.remove', 'compare.pick_more',
        'column.table', 'column.solves', 'column.best', 'column.rating', 'column.result',
        'empty.no_entrants', 'empty.no_match', 'empty.no_puzzle', 'empty.no_history', 'empty.first_time',
        'empty.nobody_logged', 'empty.not_rated', 'empty.select', 'empty.no_results', 'loading', 'opted_out',
        'private_puzzler', 'profile', 'table', 'close',
        'result.official', 'result.live', 'result.live_note', 'result.qualified', 'result.unfinished',
        'now', 'before_round', 'rating', 'rating_place', 'skill', 'solved_total', 'best500',
        'skill.enthusiast', 'skill.apprentice', 'skill.proficient', 'skill.advanced', 'skill.expert', 'skill.master', 'skill.legend',
        'history.solves', 'history.first', 'history.best', 'history.last', 'history.first_try', 'history.place',
        'history.unfinished', 'history.as_group', 'history.lineup', 'history.members_solved',
        'prediction', 'prediction.solo', 'prediction.range', 'prediction.method.personal', 'prediction.method.general',
        'similar.brand', 'similar.size', 'similar.count', 'similar.median', 'similar.best',
        'attempts', 'attempt.first', 'attempt.unboxed', 'attempt.solo', 'attempt.pair', 'attempt.team', 'attempt.unfinished',
        'recent_similar', 'recent_form', 'recent_form.median', 'earlier_rounds',
        'puzzle.pieces', 'puzzle.brand', 'difficulty', 'difficulty.very_easy', 'difficulty.easy', 'difficulty.average',
        'difficulty.challenging', 'difficulty.hard', 'difficulty.very_hard', 'difficulty.confidence',
        'stats.solvers', 'stats.times_logged', 'stats.median', 'stats.percentiles', 'stats.histogram', 'stats.first_tries',
        'stats.first_try_median', 'stats.fastest_first_try', 'stats.top', 'stats.in_round', 'stats.group', 'stats.group_median',
        'stats.fastest', 'pick_format',
        'help', 'help.title', 'keys.focus_search', 'keys.escape', 'keys.move', 'keys.open', 'keys.compare',
        'keys.clear_compare', 'keys.slot', 'keys.help',
    ];

    public function __construct(
        private GetAnalyticsStudioRound $getAnalyticsStudioRound,
        private TranslatorInterface $translator,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws AnalyticsStudioNotFound
     */
    public function build(string $competitionId, string $roundSlug): AnalyticsStudioPage
    {
        $round = $this->getAnalyticsStudioRound->bySlug($competitionId, $roundSlug);
        $labels = [];

        foreach (self::LABEL_NAMES as $name) {
            $labels[$name] = $this->translator->trans('analytics_studio.' . $name);
        }

        return new AnalyticsStudioPage(
            round: $round,
            labels: $labels,
            serverNowMs: RoundClock::milliseconds($this->clock->now()),
        );
    }

    /**
     * The page's headers (§1.1): never stored by a shared cache (an explicit max-age keeps
     * AnonymousCacheHeadersSubscriber off - the shell carries the server's time), never indexed, no referrer beyond the
     * site.
     */
    public static function protect(Response $response): Response
    {
        $response->setPrivate();
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('no-cache');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }
}
