<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use SpeedPuzzling\Web\Controller\CompetitorPage\CompetitorListController;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Security\CompetitionResultsEntryVoter;
use SpeedPuzzling\Web\Value\CompetitionTag;
use SpeedPuzzling\Web\Value\CompetitionTagKind;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Where a scanned competition tag's QR leads (docs/features/competitions-management/live-results.md, toolkit
 * B-qr-competitor-page.md §3.3) - the name tag (`live_results_scan`) and the toolkit's line-up, table and round-entry
 * tags share it:
 *
 * 1. An organiser or referee of the event (their phone's camera) lands on the live entry of the tag's round
 *    (CompetitionTagResolver) with its entry open: `?entrant=` (person), `?table=` (table), `?entry=` (line-up, entry);
 *    a tag of nobody in the event says so (`?notice=tag_unknown`). With no round at all an organiser gets the rounds
 *    page and a referee the event page, both saying that.
 * 2. Anybody else, on an event with competitor pages on: the tag's competitor page (CompetitorPageUrl) - a tag of
 *    nobody in the event the competitor list with `?notice=tag_unknown` (CompetitorListController) - only where they
 *    answer them (a publicly visible event, or its team), else the event page.
 * 3. Anybody else otherwise - signed out, a puzzler: the event page; a draft's tag (or an unknown event's) the events
 *    list.
 *
 * An event with competitor pages off answers exactly as before the toolkit (LiveResultsScanRedirectMatrixTest).
 */
final readonly class CompetitionTagLanding
{
    public function __construct(
        private AuthorizationCheckerInterface $authorizationChecker,
        private CompetitionTagResolver $competitionTagResolver,
        private CompetitorPageUrl $competitorPageUrl,
        private CompetitionDetailUrl $competitionDetailUrl,
        private CompetitionRepository $competitionRepository,
        private UrlGeneratorInterface $urlGenerator,
        private RequestStack $requestStack,
        private TranslatorInterface $translator,
    ) {
    }

    public function redirect(string $competitionId, CompetitionTag $tag, string $locale): RedirectResponse
    {
        $canEnterResults = $this->authorizationChecker->isGranted(CompetitionResultsEntryVoter::COMPETITION_RESULTS_ENTRY, $competitionId);
        $target = $canEnterResults ? $this->competitionTagResolver->resolve($competitionId, $tag) : null;
        $round = $target?->round;

        if ($canEnterResults && $round === null && $this->authorizationChecker->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId)) {
            // Nothing to enter results in yet - the organiser learns why instead of landing on the public page
            $this->flashNoRounds($locale);
            $response = $this->redirectTo('manage_competition_rounds', ['competitionId' => $competitionId, '_locale' => $locale]);
        } elseif ($target !== null && $round !== null) {
            $opens = match (true) {
                $tag->kind === CompetitionTagKind::Table => ['table' => $tag->tableNumber()],
                // Somebody removed from the event, or a tag of another one: the live entry says so
                $target->known === false => ['notice' => 'tag_unknown'],
                $tag->kind === CompetitionTagKind::Participant => ['entrant' => $tag->id],
                $target->entry !== null => ['entry' => $target->entry->toString()],
                default => ['notice' => 'tag_unknown'],
            };

            $response = $this->redirectTo('live_results', ['roundId' => $round->roundId, ...$opens, '_locale' => $locale]);
        } else {
            if ($canEnterResults) {
                // A referee scanning before any round exists: the event page says why nothing opened
                $this->flashNoRounds($locale);
            }

            $response = new RedirectResponse($this->publicLanding($competitionId, $tag, $locale, $canEnterResults));
        }

        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    private function publicLanding(string $competitionId, CompetitionTag $tag, string $locale, bool $canEnterResults): string
    {
        try {
            $competition = $this->competitionRepository->get($competitionId);
        } catch (CompetitionNotFound) {
            return $this->urlGenerator->generate('events', ['_locale' => $locale]);
        }

        // A draft's URL is its team's (docs/features/organizations/README.md "Drafts") - a tag of one scanned by
        // anybody else leads to the events page, like a tag of an unknown event
        if ($competition->isHiddenAsDraft() && $this->authorizationChecker->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId) === false) {
            return $this->urlGenerator->generate('events', ['_locale' => $locale]);
        }

        // Competitor pages on (toolkit B): the tag's own page - for a tag of nobody in the event (any more), the event's
        // competitor list saying so (toolkit v2 D2), where they find themselves. Only where they answer this viewer
        // (CompetitorPageLoader): a publicly visible event, or its team - anybody else lands on the event page as before
        if ($canEnterResults === false && $competition->competitorPages && (
            $competition->isPubliclyVisible()
            || $this->authorizationChecker->isGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId)
        )) {
            if ($this->competitionTagResolver->knows($competitionId, $tag)) {
                return $this->competitorPageUrl->of($competitionId, $tag, $locale);
            }

            return $this->urlGenerator->generate(CompetitorListController::ROUTE, [
                'competitionId' => strtolower($competitionId),
                'notice' => CompetitorListController::NOTICE_TAG_UNKNOWN,
                '_locale' => $locale,
            ]);
        }

        try {
            return $this->competitionDetailUrl->of($competitionId, $locale);
        } catch (CompetitionNotFound) {
            return $this->urlGenerator->generate('events', ['_locale' => $locale]);
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    private function redirectTo(string $route, array $parameters): RedirectResponse
    {
        return new RedirectResponse($this->urlGenerator->generate($route, $parameters));
    }

    private function flashNoRounds(string $locale): void
    {
        $session = $this->requestStack->getSession();

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('warning', $this->translator->trans('live_results.scan.no_rounds', locale: $locale));
        }
    }
}
