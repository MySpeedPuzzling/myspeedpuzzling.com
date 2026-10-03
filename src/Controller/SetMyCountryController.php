<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\UnknownCountryCode;
use SpeedPuzzling\Web\Message\SetPlayerCountry;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Add your country" (docs/features/players-page/README.md): the nudge's Save on the Players page and the player's own
 * profile. Lands on the Players page of the chosen country - the place the player has just joined. Never answers 200
 * (Turbo Drive drops a 200 answer to a form submission): a redirect, or 422 for a code that is not a country.
 */
final class SetMyCountryController extends AbstractController
{
    // Session-backed: the form is only ever rendered for a signed-in player, whose session already exists
    public const string CSRF_TOKEN_ID = 'set_my_country';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/puzzleri/moje-zeme',
            'en' => '/en/puzzlers/my-country',
            'es' => '/es/jugadores/mi-pais',
            'ja' => '/ja/プレイヤー/私の国',
            'fr' => '/fr/joueurs/mon-pays',
            'de' => '/de/puzzler/mein-land',
        ],
        name: 'set_my_country',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return $this->redirectToRoute('players');
        }

        if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            $this->addFlash('warning', $this->translator->trans('players.nudge.flash.try_again'));

            return $this->redirectToRoute('players');
        }

        $code = $request->request->getString('country');

        try {
            $this->messageBus->dispatch(new SetPlayerCountry($profile->playerId, $code));
        } catch (UnknownCountryCode) {
            // Only a tampered or outdated form gets here - the select offers known countries only
            return $this->render('players/set_country_failed.html.twig', [], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $country = CountryCode::fromCode($code);
        assert($country !== null);

        $this->addFlash('success', $this->translator->trans('players.nudge.flash.saved', [
            '%country%' => $country->value,
        ]));

        return $this->redirectToRoute('players', ['scope' => $country->name]);
    }
}
