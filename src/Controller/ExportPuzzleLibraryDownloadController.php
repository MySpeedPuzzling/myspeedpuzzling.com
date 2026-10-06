<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Services\Export\PuzzleLibraryExportBuilder;
use SpeedPuzzling\Web\Services\Export\SectionedExportWriter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ExportFormat;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The whole puzzle library in one file (docs/features/data-export.md) - the player's own data only.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ExportPuzzleLibraryDownloadController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private PuzzleLibraryExportBuilder $exportBuilder,
        readonly private SectionedExportWriter $writer,
        readonly private ClockInterface $clock,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/export-dat-hrace/{playerId}/knihovna/{format}',
            'en' => '/en/export-puzzler-data/{playerId}/library/{format}',
            'es' => '/es/exportar-datos-puzzler/{playerId}/biblioteca/{format}',
            'ja' => '/ja/パズラーデータエクスポート/{playerId}/ライブラリ/{format}',
            'fr' => '/fr/export-donnees-puzzler/{playerId}/bibliotheque/{format}',
            'de' => '/de/puzzler-daten-exportieren/{playerId}/bibliothek/{format}',
        ],
        name: 'export_puzzle_library_download',
        requirements: ['format' => 'json|xlsx|csv|xml'],
    )]
    public function __invoke(string $playerId, string $format): Response
    {
        $player = $this->retrieveLoggedUserProfile->getProfile();

        if ($player === null) {
            return $this->redirectToRoute('my_profile');
        }

        if ($playerId !== $player->playerId) {
            throw $this->createAccessDeniedException();
        }

        $file = $this->writer->write($this->exportBuilder->build($player), ExportFormat::from($format));

        $filename = sprintf(
            'speedpuzzling-library-%s.%s',
            $this->clock->now()->format('Y-m-d'),
            $file->fileExtension,
        );

        return new Response($file->content, Response::HTTP_OK, [
            'Content-Type' => $file->contentType,
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
