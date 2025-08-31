<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Admin;

use Auth0\Symfony\Models\User;
use Exception;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\ExcelParsingFailed;
use SpeedPuzzling\Web\FormData\ExcelImportFormData;
use SpeedPuzzling\Web\FormType\ExcelImportFormType;
use SpeedPuzzling\Web\Message\UpdateCompetitionParticipant;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Security\AdminAccessVoter;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ImportCompetitionPuzzlersUploadController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRepository $competitionRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(
        path: '/admin/import-competition-puzzlers/{competitionId}',
        name: 'admin_import_competition_puzzlers_upload',
        requirements: ['competitionId' => '[0-9a-fA-F-]+']
    )]
    #[IsGranted(AdminAccessVoter::ADMIN_ACCESS)]
    public function __invoke(
        string $competitionId,
        Request $request,
        #[CurrentUser] User $user,
    ): Response {
        try {
            $competition = $this->competitionRepository->get($competitionId);
        } catch (CompetitionNotFound) {
            $this->addFlash('error', 'Competition not found.');

            return $this->redirectToRoute('admin_import_competition_puzzlers');
        }

        $formData = new ExcelImportFormData();
        $form = $this->createForm(ExcelImportFormType::class, $formData);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && $formData->file !== null) {
            try {
                $importedCount = $this->processExcelFile($formData->file, $competitionId);
                $this->addFlash('success', "Successfully imported {$importedCount} participants.");

                return $this->redirectToRoute('admin_import_competition_puzzlers');
            } catch (ExcelParsingFailed $e) {
                $this->addFlash('danger', 'Excel parsing failed: ' . $e->getMessage());

                $this->logger->error('Failed to import participants excel', [
                    'exception' => $e,
                ]);
            }
        }

        return $this->render('admin/import_competition_puzzlers_upload.html.twig', [
            'competition' => $competition,
            'form' => $form->createView(),
        ]);
    }

    private function processExcelFile(UploadedFile $file, string $competitionId): int
    {
        try {
            $spreadsheet = IOFactory::load($file->getPathname());
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();

            if (empty($rows)) {
                throw new ExcelParsingFailed('Excel file is empty.');
            }

            /** @var array<string> $headerRow */
            $headerRow = array_shift($rows);

            /** @var array<string> $headers */
            $headers = [];

            foreach ($headerRow as $header) {
                $headers[] = trim($header);
            }

            // Find required column indices
            $nameIndex = $this->findColumnIndex($headers, 'Name');
            $countryIndex = $this->findColumnIndex($headers, 'Country');
            $groupIdIndex = $this->findColumnIndex($headers, 'Round_id');

            if ($nameIndex === null || $countryIndex === null || $groupIdIndex === null) {
                throw new ExcelParsingFailed('Required columns not found: Name, Country, Round_id');
            }

            $importedCount = 0;

            foreach ($rows as $rowIndex => $row) {
                /** @var null|string $groupIdValue */
                $groupIdValue = $row[$groupIdIndex] ?? null;

                if ($groupIdValue === null || $groupIdValue === '') {
                    continue;
                }

                $groupIdString = trim($groupIdValue);

                if ($groupIdString === '') {
                    continue;
                }

                /** @var null|string $nameValue */
                $nameValue = $row[$nameIndex] ?? null;

                /** @var null|string $countryValue */
                $countryValue = $row[$countryIndex] ?? null;

                if ($nameValue === null || $countryValue === null) {
                    continue;
                }

                $name = trim($nameValue);
                $countryCode = trim($countryValue);
                $groupId = $groupIdString;

                if (empty($name) || empty($countryCode)) {
                    continue;
                }

                if (Uuid::isValid($groupId) === false) {
                    throw new ExcelParsingFailed("Invalid Round_id UUID '{$groupId}' at row " . ($rowIndex + 2));
                }

                // Dispatch message
                $message = new UpdateCompetitionParticipant(
                    Uuid::fromString($competitionId),
                    Uuid::fromString($groupId),
                    $name,
                    CountryCode::fromCode($countryCode),
                );

                $this->messageBus->dispatch($message);
                $importedCount++;
            }

            return $importedCount;
        } catch (Exception $e) {
            if ($e instanceof ExcelParsingFailed) {
                throw $e;
            }

            throw new ExcelParsingFailed('Failed to process Excel file: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string> $headers
     */
    private function findColumnIndex(array $headers, string $columnName): null|int
    {
        foreach ($headers as $index => $header) {
            if (strtolower(trim($header)) === strtolower($columnName)) {
                return $index;
            }
        }
        return null;
    }
}
