<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\MembershipPlanSwitchNotPossible;
use SpeedPuzzling\Web\Services\Membership\MembershipPlanSwitching;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Switch to the yearly / monthly plan" on the membership page - sends the member to Stripe's confirmation
 * page, or back with a note when the switch is not possible (docs/features/stripe.md "Switching plans").
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class SwitchMembershipPlanController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'membership_plan_switch';

    public function __construct(
        readonly private MembershipPlanSwitching $membershipPlanSwitching,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/clenstvi/zmena-planu',
            'en' => '/en/membership/switch-plan',
            'es' => '/es/membresia/cambiar-plan',
            'ja' => '/ja/メンバーシップ/プラン変更',
            'fr' => '/fr/adhesion/changer-de-formule',
            'de' => '/de/mitgliedschaft/tarif-wechseln',
        ],
        name: 'membership_switch_plan',
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

            $url = $this->membershipPlanSwitching->confirmationUrl($player->playerId, $customerId, $player->locale);
        } catch (MembershipPlanSwitchNotPossible) {
            $this->addFlash('warning', $this->translator->trans('flashes.membership_plan_switch_not_possible'));

            return $this->redirectToRoute('membership');
        }

        return $this->redirect($url, Response::HTTP_SEE_OTHER);
    }
}
