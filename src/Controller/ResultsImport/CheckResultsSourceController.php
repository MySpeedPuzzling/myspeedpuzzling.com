<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\ResultsImport;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\CheckResultsSource;
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
 * "Check sheet" (docs/features/event-results-import/results-import-page.md): reads the source's sheet (seconds, outside
 * a transaction - CheckResultsSource) and keeps it as the latest check, then back to the page, which shows it. Never a
 * 200 to the POST (Turbo Drive would drop it): a redirect, also when the token is stale.
 */
final class CheckResultsSourceController extends AbstractController
{
    public const string CSRF_TOKEN_PREFIX = 'results_import_check_';

    public function __construct(
        readonly private ResultsImportPage $resultsImportPage,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MessageBusInterface $messageBus,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/organizations/{slug}/results-import/{sourceId}/check',
        name: 'results_import_check',
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $slug, string $sourceId): Response
    {
        $source = $this->resultsImportPage->sourceOf($this->resultsImportPage->organization($slug), $sourceId);
        $profile = $this->retrieveLoggedUserProfile->getProfile() ?? throw new AccessDeniedException();
        $back = $this->redirectToRoute('results_import', ['slug' => $slug, 'sourceId' => $source->id->toString()]);

        if ($this->isCsrfTokenValid(self::CSRF_TOKEN_PREFIX . $source->id->toString(), $request->request->getString('_token')) === false) {
            $this->addFlash('danger', $this->translator->trans('results_import.flash.try_again'));

            return $back;
        }

        $this->messageBus->dispatch(new CheckResultsSource(
            checkId: Uuid::uuid7()->toString(),
            sourceId: $source->id->toString(),
            checkedByPlayerId: $profile->playerId,
        ));

        return $back;
    }
}
