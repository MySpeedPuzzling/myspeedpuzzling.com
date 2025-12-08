<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Auth0\Symfony\Models\User;
use SpeedPuzzling\Web\Query\GetExportableSolvingTimes;
use SpeedPuzzling\Web\Services\PuzzlerDataExporter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ExportFormat;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ExportPuzzlerDataDownloadController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private GetExportableSolvingTimes $getExportableSolvingTimes,
        readonly private PuzzlerDataExporter $exporter,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/export-dat-hrace/{playerId}/stahnout/{format}',
            'en' => '/en/export-puzzler-data/{playerId}/download/{format}',
            'es' => '/es/exportar-datos-puzzler/{playerId}/descargar/{format}',
            'ja' => '/ja/パズラーデータエクスポート/{playerId}/ダウンロード/{format}',
            'fr' => '/fr/export-donnees-puzzler/{playerId}/telecharger/{format}',
            'de' => '/de/puzzler-daten-exportieren/{playerId}/herunterladen/{format}',
        ],
        name: 'export_puzzler_data_download',
        requirements: ['format' => 'json|xlsx|csv|xml'],
    )]
    public function __invoke(
        string $playerId,
        string $format,
        #[CurrentUser] User $user,
    ): Response {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('my_profile');
        }

        if ($playerId !== $player->playerId) {
            throw $this->createAccessDeniedException();
        }

        $exportFormat = ExportFormat::from($format);
        $data = $this->getExportableSolvingTimes->byPlayerId($playerId);
        $content = $this->exporter->export($data, $exportFormat);

        $filename = sprintf(
            'speedpuzzling-export-%s.%s',
            date('Y-m-d'),
            $exportFormat->fileExtension(),
        );

        return new Response($content, Response::HTTP_OK, [
            'Content-Type' => $exportFormat->contentType(),
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
        ]);
    }
}
