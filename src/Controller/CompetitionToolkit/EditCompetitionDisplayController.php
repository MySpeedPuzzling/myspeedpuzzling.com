<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetCompetitionDisplays;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplayPayload;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The display design editor (D-displays-overlays.md §8.1 `edit_competition_display`, §8.2; competition toolkit D6) -
 * desktop/tablet. The page hands display_editor_controller.js (lazy) the stored design and the display's own first
 * state (the same CompetitionDisplayPayload the display page draws - secret puzzles by the public rule, R §1.5) plus
 * the editor's labels. Saves go to save_competition_display, pictures to upload_competition_display_image. The voter
 * is checked on the event of the display loaded from the database, never on an id from the request.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class EditCompetitionDisplayController extends AbstractController
{
    private const string LABEL_PREFIX = 'competition_display.editor.';

    public function __construct(
        private readonly GetCompetitionDisplays $getCompetitionDisplays,
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly CompetitionDisplayPayload $competitionDisplayPayload,
        private readonly TranslatorInterface $translator,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/editor-obrazovky/{displayId}',
            'en' => '/en/display-editor/{displayId}',
            'es' => '/es/display-editor/{displayId}',
            'ja' => '/ja/display-editor/{displayId}',
            'fr' => '/fr/display-editor/{displayId}',
            'de' => '/de/display-editor/{displayId}',
        ],
        name: 'edit_competition_display',
        requirements: ['displayId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $displayId): Response
    {
        $display = $this->getCompetitionDisplays->byId($displayId);
        $this->denyAccessUnlessGranted(CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE, $display->competitionId);

        $page = $this->competitionDisplayPayload->pageForToken($display->token);
        // The display's Mercure subscription is of no use to the editor
        $state = $page['state'];
        unset($state['mercure']);

        $response = $this->render('competition_toolkit/display_editor.html.twig', [
            'competition' => $this->getCompetitionEvents->byId($display->competitionId),
            'display' => $display,
            'config' => [
                'competitionId' => $display->competitionId,
                'kind' => $display->kind->value,
                'design' => $page['design'] === [] ? ['schema' => 1] : $page['design'],
                'designVersion' => $display->designVersion,
                'state' => $state,
                'labels' => $this->labels($request->getLocale()),
                'saveUrl' => $this->generateUrl('save_competition_display', ['displayId' => $display->id]),
                'uploadUrl' => $this->generateUrl('upload_competition_display_image', ['displayId' => $display->id]),
                'designUrl' => $this->generateUrl('competition_display_design', ['token' => $display->token]),
                'csrfToken' => $this->csrfTokenManager->getToken(OfficialResultsApi::CSRF_TOKEN_ID)->getValue(),
            ],
        ]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    /**
     * Every `competition_display.editor.*` text in the request's language (English where it has none yet) - the
     * schema-driven panel looks its labels up by key, so no list of keys is kept twice.
     *
     * @return array<string, string>
     */
    private function labels(string $locale): array
    {
        if (!$this->translator instanceof TranslatorBagInterface) {
            return [];
        }

        $labels = [];
        $catalogue = $this->translator->getCatalogue($locale);

        while ($catalogue !== null) {
            foreach ($catalogue->all('messages') as $key => $text) {
                $label = substr((string) $key, strlen(self::LABEL_PREFIX));

                if (str_starts_with((string) $key, self::LABEL_PREFIX) && is_string($text) && !isset($labels[$label])) {
                    $labels[$label] = $text;
                }
            }

            $catalogue = $catalogue->getFallbackCatalogue();
        }

        return $labels;
    }
}
