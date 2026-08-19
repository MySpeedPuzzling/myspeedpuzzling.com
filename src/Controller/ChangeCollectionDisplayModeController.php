<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\ChangeCollectionDisplayMode;
use SpeedPuzzling\Web\Services\ResolveCollectionDisplay;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CollectionDisplayMode;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The "Display" control of collection pages: Off / My times / My times +
 * predictions. Persists the viewer's choice (a player column, so it survives
 * logout and follows them across devices) and sends them back to the page they
 * came from. Predictions are members-only and respect the time-predictions
 * opt-out, so an ineligible player asking for them is downgraded to "My times"
 * here rather than trusted - the form only offers what the player may use, but
 * the request body is theirs to edit.
 */
final class ChangeCollectionDisplayModeController extends AbstractController
{
    public const string CSRF_TOKEN_ID = 'collection_display_mode';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/zobrazeni-kolekce',
            'en' => '/en/collection-display-mode',
            'es' => '/es/modo-de-visualizacion-de-coleccion',
            'ja' => '/ja/コレクション表示モード',
            'fr' => '/fr/mode-d-affichage-de-collection',
            'de' => '/de/sammlung-anzeigemodus',
        ],
        name: 'collection_display_mode',
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function __invoke(Request $request): Response
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();
        assert($profile !== null);

        $returnUrl = ReturnUrl::tryFrom($request->query->get('return'));
        $redirect = $returnUrl !== null
            ? $this->redirect($returnUrl->path)
            : $this->redirectToRoute('puzzle_library', ['playerId' => $profile->playerId]);

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, (string) $request->request->get('_token'))) {
            return $redirect;
        }

        $mode = CollectionDisplayMode::tryFrom((string) $request->request->get('mode'));

        if ($mode === null) {
            return new Response('', Response::HTTP_BAD_REQUEST);
        }

        if ($mode->showsPredictions() && ResolveCollectionDisplay::predictionsAllowed($profile) === false) {
            $mode = CollectionDisplayMode::Times;
        }

        $this->messageBus->dispatch(new ChangeCollectionDisplayMode(
            playerId: $profile->playerId,
            mode: $mode,
        ));

        return $redirect;
    }
}
