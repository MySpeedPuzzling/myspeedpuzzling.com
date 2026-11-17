<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use JsonException;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\CompetitionDisplayNotFound;
use SpeedPuzzling\Web\Exceptions\DisplayDesignConflict;
use SpeedPuzzling\Web\Exceptions\InvalidDisplayDesign;
use SpeedPuzzling\Web\Message\SaveCompetitionDisplayDesign;
use SpeedPuzzling\Web\Query\GetCompetitionDisplays;
use SpeedPuzzling\Web\Results\DisplayDesignError;
use SpeedPuzzling\Web\Results\SavedCompetitionDisplayDesign;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The display editor's Save (D-displays-overlays.md §8.1 `save_competition_display`): a JSON POST (or PUT) of
 * `{"design": {...}, "baseVersion": 3, "liveConfirmed": false}` →
 * - 200 `{designVersion, design, usesResults, usesLiveResults}` - the normalised design the editor adopts (an empty
 *   `style` / `props` comes back as [], schema.js reads it as {});
 * - 409 `{error: "conflict", designVersion}` - saved meanwhile elsewhere, the editor offers load or overwrite;
 * - 422 `{error: "invalid_design", errors: [{path, code, message}]}` - JSON pointers the editor highlights; code
 *   `live_not_confirmed` = ask the organiser once, then resend with `liveConfirmed: true`;
 * - 400 `invalid_json` / `invalid_request`, 413 `too_large`, 404 `not_found`, and OfficialResultsApi's 401/403/415
 *   (signed in, COMPETITION_TOOLKIT_MANAGE on the display's own event, JSON, the `X-CSRF-Token` header).
 */
final class SaveCompetitionDisplayDesignController extends AbstractController
{
    // The normalised design is at most 64 KB; the raw request may carry dropped keys and spaces - not megabytes
    private const int MAX_BODY_BYTES = 262144;

    public function __construct(
        private readonly GetCompetitionDisplays $getCompetitionDisplays,
        private readonly OfficialResultsApi $api,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/editor-obrazovky/{displayId}/ulozit',
            'en' => '/en/display-editor/{displayId}/save',
            'es' => '/es/display-editor/{displayId}/save',
            'ja' => '/ja/display-editor/{displayId}/save',
            'fr' => '/fr/display-editor/{displayId}/save',
            'de' => '/de/display-editor/{displayId}/save',
        ],
        name: 'save_competition_display',
        requirements: ['displayId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST', 'PUT'],
    )]
    public function __invoke(Request $request, string $displayId): JsonResponse
    {
        try {
            $display = $this->getCompetitionDisplays->byId($displayId);
        } catch (CompetitionDisplayNotFound) {
            return OfficialResultsApi::error('not_found', JsonResponse::HTTP_NOT_FOUND);
        }

        // The voter on the event of the display loaded from the database, never on an id from the request
        $playerId = $this->api->authorise($request, $display->competitionId, write: true, attribute: CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE);

        if ($playerId instanceof JsonResponse) {
            return $playerId;
        }

        $content = $request->getContent();

        if (strlen($content) > self::MAX_BODY_BYTES) {
            return OfficialResultsApi::error('too_large', JsonResponse::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        try {
            // Objects stay stdClass: the sanitizer tells an object from a list exactly as the browser does
            $body = json_decode($content, false, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return OfficialResultsApi::error('invalid_json', JsonResponse::HTTP_BAD_REQUEST);
        }

        if (!$body instanceof stdClass || !property_exists($body, 'design') || !is_int($body->baseVersion ?? null)) {
            return OfficialResultsApi::error('invalid_request', JsonResponse::HTTP_BAD_REQUEST);
        }

        try {
            $envelope = $this->messageBus->dispatch(new SaveCompetitionDisplayDesign(
                displayId: $display->id,
                design: $body->design,
                baseVersion: $body->baseVersion,
                liveConfirmed: ($body->liveConfirmed ?? false) === true,
            ));
        } catch (DisplayDesignConflict $conflict) {
            return OfficialResultsApi::error('conflict', JsonResponse::HTTP_CONFLICT, [
                'designVersion' => $conflict->currentVersion,
            ]);
        } catch (InvalidDisplayDesign $invalid) {
            return OfficialResultsApi::error('invalid_design', JsonResponse::HTTP_UNPROCESSABLE_ENTITY, [
                'errors' => array_map(fn (DisplayDesignError $error): array => [
                    ...$error->toArray(),
                    'message' => $this->translator->trans('competition_display.design_error.' . $error->code),
                ], $invalid->errors),
            ]);
        }

        $saved = $envelope->last(HandledStamp::class)?->getResult();
        assert($saved instanceof SavedCompetitionDisplayDesign);

        return OfficialResultsApi::json([
            'designVersion' => $saved->designVersion,
            'design' => $saved->design,
            'usesResults' => $saved->usesResults,
            'usesLiveResults' => $saved->usesLiveResults,
        ]);
    }
}
