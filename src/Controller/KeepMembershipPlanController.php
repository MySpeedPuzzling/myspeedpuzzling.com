<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\MembershipPlanSwitchNotPossible;
use SpeedPuzzling\Web\Message\UpdateMembershipSubscription;
use SpeedPuzzling\Web\Query\GetPlayerMembership;
use SpeedPuzzling\Web\Services\Membership\MembershipPlanSwitching;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Keep the yearly plan" - undoes a switch booked for the end of the paid year
 * (docs/features/stripe.md "Switching plans"). Nothing is charged; the subscription carries on as it is.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class KeepMembershipPlanController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'membership_keep_plan';

    public function __construct(
        readonly private MembershipPlanSwitching $membershipPlanSwitching,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetPlayerMembership $getPlayerMembership,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/clenstvi/ponechat-plan',
            'en' => '/en/membership/keep-plan',
            'es' => '/es/membresia/mantener-plan',
            'ja' => '/ja/メンバーシップ/プラン維持',
            'fr' => '/fr/adhesion/garder-la-formule',
            'de' => '/de/mitgliedschaft/tarif-behalten',
        ],
        name: 'membership_keep_plan',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('homepage');
        }

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token'))) {
            return $this->redirectToRoute('membership');
        }

        $customerId = $player->stripeCustomerId;

        try {
            if ($customerId === null) {
                throw new MembershipPlanSwitchNotPossible();
            }

            $this->membershipPlanSwitching->keepCurrentPlan($player->playerId, $customerId);
        } catch (MembershipPlanSwitchNotPossible) {
            $this->addFlash('warning', $this->translator->trans('flashes.membership_plan_keep_not_possible'));

            return $this->redirectToRoute('membership');
        }

        // Released - read the subscription now, so the page no longer shows the booked change
        $subscriptionId = $this->getPlayerMembership->byId($player->playerId)->stripeSubscriptionId;

        if ($subscriptionId !== null) {
            try {
                $this->messageBus->dispatch(new UpdateMembershipSubscription($subscriptionId));
            } catch (HandlerFailedException $e) {
                $this->logger->warning('Reading the subscription after keeping the plan failed', [
                    'subscription_id' => $subscriptionId,
                    'exception' => $e,
                ]);
            }
        }

        $this->addFlash('success', $this->translator->trans('flashes.membership_plan_kept'));

        return $this->redirectToRoute('membership');
    }
}
