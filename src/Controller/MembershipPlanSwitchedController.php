<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\MembershipNotFound;
use SpeedPuzzling\Web\Message\UpdateMembershipSubscription;
use SpeedPuzzling\Web\Query\GetPlayerMembership;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Where Stripe sends a member after confirming a plan switch. The webhook brings the same news, maybe a few
 * seconds later - reading the subscription here makes the membership page show the new plan right away.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class MembershipPlanSwitchedController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetPlayerMembership $getPlayerMembership,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
        readonly private LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/clenstvi/plan-zmenen',
            'en' => '/en/membership/plan-switched',
            'es' => '/es/membresia/plan-cambiado',
            'ja' => '/ja/メンバーシップ/プラン変更完了',
            'fr' => '/fr/adhesion/formule-changee',
            'de' => '/de/mitgliedschaft/tarif-gewechselt',
        ],
        name: 'membership_plan_switched',
    )]
    public function __invoke(): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('homepage');
        }

        try {
            $subscriptionId = $this->getPlayerMembership->byId($player->playerId)->stripeSubscriptionId;
        } catch (MembershipNotFound) {
            return $this->redirectToRoute('membership');
        }

        if ($subscriptionId !== null) {
            try {
                $this->messageBus->dispatch(new UpdateMembershipSubscription($subscriptionId));
            } catch (HandlerFailedException $e) {
                // The webhook catches up - nothing the member has to do
                $this->logger->warning('Reading the subscription after a plan switch failed', [
                    'subscription_id' => $subscriptionId,
                    'exception' => $e,
                ]);
            }
        }

        $this->addFlash('success', $this->translator->trans('flashes.membership_plan_switched'));

        return $this->redirectToRoute('membership');
    }
}
