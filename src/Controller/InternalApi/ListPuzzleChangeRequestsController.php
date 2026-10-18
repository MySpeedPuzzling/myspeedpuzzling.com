<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use DateTimeInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\InternalApiInvalidInput;
use SpeedPuzzling\Web\Query\GetChangeRequestsForInternalApi;
use SpeedPuzzling\Web\Results\ChangeRequestForInternalApi;
use SpeedPuzzling\Web\Services\UploaderHelper;
use SpeedPuzzling\Web\Value\PuzzleChangeRequestSource;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Puzzle change requests, a page at a time (docs/features/internal-api.md "Puzzle change requests") -
 * `?source=player|internal_api|image_pipeline&tag=&status=pending|approved|rejected|outdated|all&limit=1..500&after=`:
 * each with its pipeline metadata, its decision and fetchable full-size URLs of the original and the proposed picture.
 * It feeds the image pipeline's auto-resolve and its precision calibration. `nextCursor` is the `after` of the next
 * page, null on the last one. Requests of puzzles with a hidden picture are never listed.
 */
final class ListPuzzleChangeRequestsController extends AbstractController
{
    private const array KNOWN_PARAMETERS = ['source', 'tag', 'status', 'limit', 'after'];

    private const string ALL = 'all';

    public function __construct(
        private readonly GetChangeRequestsForInternalApi $getChangeRequestsForInternalApi,
        private readonly UploaderHelper $uploaderHelper,
    ) {
    }

    #[Route(
        path: '/internal-api/puzzle-change-requests',
        methods: ['GET'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $errors = [];

        foreach ($request->query->keys() as $parameter) {
            if (in_array($parameter, self::KNOWN_PARAMETERS, true) === false) {
                $errors[(string) $parameter] = sprintf('is not a parameter of this endpoint (known: %s).', implode(', ', self::KNOWN_PARAMETERS));
            }
        }

        $statusValue = self::parameter($request, 'status') ?? self::ALL;
        $status = $statusValue === self::ALL ? null : PuzzleReportStatus::tryFrom($statusValue);

        if ($statusValue !== self::ALL && $status === null) {
            $errors['status'] = 'must be one of: pending, approved, rejected, outdated, all.';
        }

        $sourceValue = self::parameter($request, 'source');
        $source = $sourceValue !== null ? PuzzleChangeRequestSource::tryFrom($sourceValue) : null;

        if ($sourceValue !== null && $source === null) {
            $errors['source'] = sprintf('must be one of: %s.', implode(', ', array_column(PuzzleChangeRequestSource::cases(), 'value')));
        }

        $tag = self::parameter($request, 'tag');

        if ($tag !== null && preg_match('/^[A-Za-z0-9_.:-]{1,50}$/', $tag) !== 1) {
            $errors['tag'] = 'must be 1-50 characters of letters, digits, "_", "-", "." or ":".';
        }

        $limitValue = self::parameter($request, 'limit');
        $limit = GetChangeRequestsForInternalApi::DEFAULT_LIMIT;

        if ($limitValue !== null) {
            $limit = filter_var($limitValue, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => GetChangeRequestsForInternalApi::MAX_LIMIT]]);

            if ($limit === false) {
                $errors['limit'] = sprintf('must be a whole number from 1 to %d.', GetChangeRequestsForInternalApi::MAX_LIMIT);
                $limit = GetChangeRequestsForInternalApi::DEFAULT_LIMIT;
            }
        }

        $after = self::parameter($request, 'after');

        if ($after !== null && Uuid::isValid($after) === false) {
            $errors['after'] = 'must be a change request id (the nextCursor of the previous page).';
        }

        if ($errors !== []) {
            throw new InternalApiInvalidInput($errors);
        }

        $page = $this->getChangeRequestsForInternalApi->page($status, $source, $tag, $limit, $after);

        return new JsonResponse([
            'changeRequests' => array_map($this->item(...), $page['items']),
            'nextCursor' => $page['nextCursor'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function item(ChangeRequestForInternalApi $changeRequest): array
    {
        $decision = $changeRequest->decision;

        return [
            'id' => $changeRequest->id,
            'puzzleId' => $changeRequest->puzzleId,
            'puzzleName' => $changeRequest->puzzleName,
            'status' => $changeRequest->status->value,
            'source' => $changeRequest->source->value,
            'tags' => $changeRequest->tags,
            'confidence' => $changeRequest->confidence,
            'modelVersion' => $changeRequest->modelVersion,
            'sourceImageFingerprint' => $changeRequest->sourceImageFingerprint,
            // Full-size, as stored - downloadable from anywhere
            'originalImageUrl' => $this->url($changeRequest->originalImage),
            'proposedImageUrl' => $this->url($changeRequest->proposedImage),
            // The copy kept before an approved image pipeline proposal replaced the picture
            'originalArchiveUrl' => $this->url($changeRequest->originalArchivePath),
            'createdAt' => $changeRequest->submittedAt->format(DateTimeInterface::ATOM),
            'decision' => $decision !== null ? [
                'outcome' => $decision['outcome'],
                'decidedAt' => $decision['decidedAt']->format(DateTimeInterface::ATOM),
                'decidedBy' => $decision['decidedById'] !== null
                    ? ['playerId' => $decision['decidedById'], 'name' => $decision['decidedByName']]
                    : null,
                'source' => $decision['source'],
                'note' => $decision['note'],
                'rejectionReason' => $decision['rejectionReason'],
                // An approval's picture choice: proposed (the proposed picture was taken), keep, upload; null otherwise
                'image' => $decision['image'],
            ] : null,
        ];
    }

    private function url(null|string $path): null|string
    {
        return $path !== null ? $this->uploaderHelper->getPublicPath($path) : null;
    }

    private static function parameter(Request $request, string $name): null|string
    {
        $value = $request->query->all()[$name] ?? null;

        if (is_string($value) === false) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
