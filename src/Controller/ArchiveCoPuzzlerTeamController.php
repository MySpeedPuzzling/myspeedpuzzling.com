<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\ArchivePuzzlingTeam;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The × on a team the co-puzzler picker offers: the same archive as the Pairs & teams page ("keep it out of my
 * shortcuts"), sent with fetch so the add-time form around it never notices. archive=0 is the picker's Undo.
 * Answers JSON only - nothing but copuzzler_picker_controller.js posts here.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ArchiveCoPuzzlerTeamController extends AbstractController
{
    // Stateless (config/packages/csrf.php): the picker sits on every add-time form
    public const string CSRF_TOKEN_ID = 'copuzzler_archive';

    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
    ) {
    }

    #[Route(
        path: '/{_locale}/my-co-puzzlers/archive',
        name: 'my_co_puzzlers_archive',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            return $this->privateJson(['error' => 'csrf'], Response::HTTP_FORBIDDEN);
        }

        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->privateJson(['error' => 'no_player'], Response::HTTP_FORBIDDEN);
        }

        $archive = $request->request->getString('archive') !== '0';

        // Not a member / no such team: HTTP exceptions, answered 403 / 404
        $this->messageBus->dispatch(new ArchivePuzzlingTeam($request->request->getString('teamId'), $player->playerId, $archive));

        return $this->privateJson(['archived' => $archive]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function privateJson(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        $response = new JsonResponse($data, $status);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
