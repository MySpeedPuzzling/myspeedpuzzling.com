<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\AnnouncementModals;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\ClaimAnnouncementModalImpression;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Services\PlatformDetector;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\AnnouncementModal;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Which announcement modal - if any - the page being rendered opens by itself
 * (docs/features/announcement-modals.md). At most one per page view, at most once per player ever.
 *
 * Everything up to the claim is decided from the request and the viewer's already loaded profile, so
 * an ordinary page view costs no query. The claim is the single write, and it happens once per player
 * and modal in their lifetime - see ClaimAnnouncementModalImpressionHandler for why it comes *before*
 * the modal is rendered rather than being reported by the browser afterwards.
 */
final class ResolveAnnouncementModal implements ResetInterface
{
    /** Nobody gets two different announcement modals within this many days */
    public const int COOLDOWN_DAYS = 14;

    /**
     * Pages in the middle of something: paying, signing in, timing a puzzle, filling a form, a reveal moment, running an
     * event (the organisers' tools - an offer over the participants sheet or the results desk on event
     * day is in the way). A modal skipped here is not lost - it waits for the next page that can take
     * the interruption.
     */
    public const array QUIET_ROUTES = [
        'membership', 'buy_membership', 'billing_portal', 'stripe_checkout_success', 'claim_voucher',
        'free_trial_started',
        'login', 'logout', 'register', 'registration_welcome', 'getting_started', 'verify_email',
        'sign_in_link_request', 'sign_in_link_check', 'set_password_after_sign_in_link', 'sign_in_changes',
        'request_password_reset', 'password_reset', 'account_set_password', 'change_account_password',
        'change_account_email', 'request_account_deletion', 'confirm_account_deletion', 'account_deleted',
        'social_register_confirm', 'oauth2_authorize', 'oauth2_device_code', 'claim_oauth2_credentials',
        'edit_profile', 'puzzle_add', 'legacy_add_time', 'edit_time', 'delete_time', 'added_time_recap',
        'added_tracking_recap', 'xp_launch_reveal', 'badge_reveals',
        'stopwatch', 'stopwatch_puzzle', 'finish_stopwatch', 'round_stopwatch', 'manage_round_stopwatch',
        'participants_sheet', 'results_desk', 'round_seating', 'live_results', 'competition_results_overview',
        'competition_check_in', 'competition_name_tags',
        // Online competitions: the organiser's set-up (docs/features/online-competitions/)
        'online_round_settings', 'online_links',
        // Online competitions: the participant's page - a camera and a running clock
        'compete_page',
        // Online competitions: the organiser's control room, the review and its accuracy report
        'online_control_room', 'online_review', 'online_accuracy',
        // Online competitions: the host's studio - on air
        'online_studio',
        // Online competitions: the public watch page - full-screen video, also an OBS browser source
        'online_watch',
        // The navigation's Help page, the feedback form and "Where did it go?" - a player looking for help or for a moved
        // item is in the middle of something (docs/features/navigation decision 57) - and the Menu page its "Now" links
        // open on the row they name (LAUNCH review F2)
        'help', 'feedback', 'where_did_it_go', 'menu',
    ];

    private bool $resolved = false;
    private null|AnnouncementModal $modal = null;

    /**
     * @param iterable<AnnouncementModalRule> $rules
     */
    public function __construct(
        #[AutowireIterator(AnnouncementModalRule::TAG)]
        readonly private iterable $rules,
        readonly private RequestStack $requestStack,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private PlatformDetector $platformDetector,
        readonly private MessageBusInterface $messageBus,
        readonly private ClockInterface $clock,
    ) {
    }

    public function forCurrentRequest(): null|AnnouncementModal
    {
        if ($this->resolved === false) {
            $this->resolved = true;
            $this->modal = $this->resolve();
        }

        return $this->modal;
    }

    public function reset(): void
    {
        $this->resolved = false;
        $this->modal = null;
    }

    private function resolve(): null|AnnouncementModal
    {
        $request = $this->requestStack->getMainRequest();

        // An error page is rendered in a sub-request of its own - it borrows the layout, not the moment
        if ($request === null || $request !== $this->requestStack->getCurrentRequest()) {
            return null;
        }

        if ($this->canBeInterrupted($request) === false) {
            return null;
        }

        // Native apps sell membership through their stores - no offers of ours in there
        if ($this->platformDetector->isWeb() === false) {
            return null;
        }

        $viewer = $this->retrieveLoggedUserProfile->getProfile();

        if ($viewer === null) {
            return null;
        }

        $now = $this->clock->now();
        $modal = $this->firstModalFor($viewer, $now);

        if ($modal === null) {
            return null;
        }

        $envelope = $this->messageBus->dispatch(new ClaimAnnouncementModalImpression($viewer->playerId, $modal));

        return $envelope->last(HandledStamp::class)?->getResult() === true ? $modal : null;
    }

    private function firstModalFor(PlayerProfile $viewer, \DateTimeImmutable $now): null|AnnouncementModal
    {
        $cooldownStart = $now->modify('-' . self::COOLDOWN_DAYS . ' days');
        $inCooldown = false;

        foreach ($viewer->modalImpressions as $displayedAt) {
            if ($displayedAt > $cooldownStart) {
                $inCooldown = true;
            }
        }

        $rules = [];

        foreach ($this->rules as $rule) {
            $rules[$rule->modal()->value] = $rule;
        }

        // Enum order is the priority
        foreach (AnnouncementModal::cases() as $modal) {
            $rule = $rules[$modal->value] ?? null;

            if ($rule === null || isset($viewer->modalImpressions[$modal->value])) {
                continue;
            }

            if ($inCooldown && $modal->ignoresCooldown() === false) {
                continue;
            }

            if ($rule->isEligible($viewer, $now)) {
                return $modal;
            }
        }

        return null;
    }

    /**
     * Only a full page a person navigated to: not a form answer, not a Turbo Frame or Live Component
     * fragment (the modal lives in the layout, a fragment would claim it and show nothing), not a
     * step of a flow that carries its way back in `?return=`.
     */
    private function canBeInterrupted(Request $request): bool
    {
        if ($request->isMethod(Request::METHOD_GET) === false) {
            return false;
        }

        if ($request->headers->has('Turbo-Frame') || $request->isXmlHttpRequest()) {
            return false;
        }

        if ($request->query->has('return')) {
            return false;
        }

        $route = $request->attributes->get('_canonical_route') ?? $request->attributes->get('_route');

        if (!is_string($route) || $route === '' || str_starts_with($route, '_') || str_starts_with($route, 'ux_live_component')) {
            return false;
        }

        if (str_starts_with($route, 'admin') || str_contains($route, 'internal_api') || str_starts_with($route, 'api_')) {
            return false;
        }

        return in_array($route, self::QUIET_ROUTES, true) === false;
    }
}
