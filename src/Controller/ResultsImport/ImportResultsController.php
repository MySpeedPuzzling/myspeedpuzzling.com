<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ResultsImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ImportResultsFromCheck;
use SpeedPuzzling\Web\Repository\ResultsImportCheckRepository;
use SpeedPuzzling\Web\Repository\ResultsImportLogRepository;
use SpeedPuzzling\Web\Services\EventResultsImport\ResultsImportPage;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Import" confirmed (docs/features/event-results-import/results-import-page.md "As built: PR 3"): the confirmed events
 * of the stored check are applied (ImportResultsFromCheck - never a new read), then the page shows what became of them.
 * Never a 200 to the POST: always a redirect. The import id comes from the confirmation, so a second submit lands on
 * the same result and imports nothing twice; only the latest check is imported from.
 */
final class ImportResultsController extends AbstractController
{
    public const string CSRF_TOKEN_PREFIX = 'results_import_import_';

    public function __construct(
        readonly private ResultsImportPage $resultsImportPage,
        readonly private ResultsImportCheckRepository $checkRepository,
        readonly private ResultsImportLogRepository $logRepository,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/organizations/{slug}/results-import/{sourceId}/import',
        name: 'results_import_import',
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $slug, string $sourceId): Response
    {
        $organization = $this->resultsImportPage->organization($slug);
        $source = $this->resultsImportPage->sourceOf($organization, $sourceId);
        $profile = $this->retrieveLoggedUserProfile->getProfile() ?? throw new AccessDeniedException();
        $sourceIdValue = $source->id->toString();

        $importId = $request->request->getString('import');
        $keys = array_values(array_filter($request->request->all('events'), is_string(...)));

        if (
            $this->isCsrfTokenValid(self::CSRF_TOKEN_PREFIX . $sourceIdValue, $request->request->getString('_token')) === false
            || Uuid::isValid($importId) === false
            || $keys === []
        ) {
            $this->addFlash('danger', $this->translator->trans('results_import.flash.try_again'));

            return $this->redirectToRoute('results_import', ['slug' => $organization->slug, 'sourceId' => $sourceIdValue]);
        }

        $importId = strtolower($importId);
        $result = $this->redirectToRoute('results_import', [
            'slug' => $organization->slug,
            'sourceId' => $sourceIdValue,
            'import' => $importId,
            '_fragment' => 'import-result',
        ]);

        // Sent twice (a double tap, a resend after a lost answer) - it is done already, show what it did
        if ($this->logRepository->find($importId) !== null) {
            return $result;
        }

        $latest = $this->checkRepository->latestOf($source);

        if ($latest === null || $latest->id->toString() !== strtolower($request->request->getString('check'))) {
            $this->addFlash('warning', $this->translator->trans('results_import.flash.newer_check'));

            return $this->redirectToRoute('results_import', ['slug' => $organization->slug, 'sourceId' => $sourceIdValue]);
        }

        $this->messageBus->dispatch(new ImportResultsFromCheck(
            importId: $importId,
            sourceId: $sourceIdValue,
            checkId: $latest->id->toString(),
            eventKeys: $keys,
            importedByPlayerId: $profile->playerId,
        ));

        return $result;
    }
}
