<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ExportPuzzlerDataPageController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetPlayerProfile $getPlayerProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/export-dat-hrace/{playerId}',
            'en' => '/en/export-puzzler-data/{playerId}',
            'es' => '/es/exportar-datos-puzzler/{playerId}',
            'ja' => '/ja/パズラーデータエクスポート/{playerId}',
            'fr' => '/fr/export-donnees-puzzler/{playerId}',
            'de' => '/de/puzzler-daten-exportieren/{playerId}',
        ],
        name: 'export_puzzler_data',
    )]
    public function __invoke(string $playerId, #[CurrentUser] User $user): Response
    {
        $loggedPlayer = $this->retrieveLoggedUserProfile->getProfile();

        if ($loggedPlayer === null) {
            return $this->redirectToRoute('my_profile');
        }

        if ($playerId !== $loggedPlayer->playerId) {
            throw $this->createAccessDeniedException();
        }

        $player = $this->getPlayerProfile->byId($playerId);

        return $this->render('export-puzzler-data.html.twig', [
            'player' => $player,
        ]);
    }
}
