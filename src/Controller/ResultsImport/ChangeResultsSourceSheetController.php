<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ResultsImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ChangeResultsSourceSheet;
use SpeedPuzzling\Web\Services\EventResultsImport\ResultsImportPage;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\EventResultsImport\ResultsSourceSheetChange;
use SpeedPuzzling\Web\Value\GoogleSheetLink;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Change sheet" (docs/features/event-results-import/results-import-page.md "As built: PR 4"): the organization's team
 * pastes the link (or the id) of another Google spreadsheet. The id is cut out of the link (GoogleSheetLink - nothing
 * else of it is used), the sheet is read once (ChangeResultsSourceSheet) and saved only when it is readable and no other
 * results source reads it; its events are then the page's latest check. Never a 200 to the POST: always a redirect with
 * a flash saying what happened - back to "Your sheet" on a refusal.
 */
final class ChangeResultsSourceSheetController extends AbstractController
{
    public const string CSRF_TOKEN_PREFIX = 'results_import_sheet_';

    public function __construct(
        readonly private ResultsImportPage $resultsImportPage,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/organizations/{slug}/results-import/{sourceId}/sheet',
        name: 'results_import_sheet',
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $slug, string $sourceId): Response
    {
        $source = $this->resultsImportPage->sourceOf($this->resultsImportPage->organization($slug), $sourceId);
        $profile = $this->retrieveLoggedUserProfile->getProfile() ?? throw new AccessDeniedException();
        $sourceIdValue = $source->id->toString();
        $refused = $this->redirectToRoute('results_import', ['slug' => $slug, 'sourceId' => $sourceIdValue, '_fragment' => 'your-sheet']);

        if ($this->isCsrfTokenValid(self::CSRF_TOKEN_PREFIX . $sourceIdValue, $request->request->getString('_token')) === false) {
            $this->addFlash('danger', $this->translator->trans('results_import.flash.try_again'));

            return $refused;
        }

        $pasted = trim($request->request->getString('spreadsheet'));
        $spreadsheetId = null;

        if (GoogleSheetLink::isSpreadsheetId($pasted)) {
            $spreadsheetId = $pasted;
        } else {
            $link = GoogleSheetLink::parse($pasted);
            $spreadsheetId = $link->spreadsheetId;

            if ($spreadsheetId === null) {
                $this->addFlash('danger', $this->translator->trans($link->problem === GoogleSheetLink::PUBLISHED
                    ? 'results_import.sheet.flash.published'
                    : 'results_import.sheet.flash.not_a_sheet'));

                return $refused;
            }
        }

        $envelope = $this->messageBus->dispatch(new ChangeResultsSourceSheet(
            checkId: Uuid::uuid7()->toString(),
            sourceId: $sourceIdValue,
            spreadsheetId: $spreadsheetId,
            changedByPlayerId: $profile->playerId,
        ));
        $outcome = $envelope->last(HandledStamp::class)?->getResult();

        if (!$outcome instanceof ResultsSourceSheetChange) {
            $this->addFlash('danger', $this->translator->trans('results_import.flash.try_again'));

            return $refused;
        }

        $this->addFlash(match ($outcome) {
            ResultsSourceSheetChange::Changed => 'success',
            ResultsSourceSheetChange::Same => 'info',
            ResultsSourceSheetChange::Taken, ResultsSourceSheetChange::Unreadable => 'danger',
        }, $this->translator->trans('results_import.sheet.flash.' . $outcome->value));

        if ($outcome !== ResultsSourceSheetChange::Changed) {
            return $refused;
        }

        return $this->redirectToRoute('results_import', ['slug' => $slug, 'sourceId' => $sourceIdValue]);
    }
}
