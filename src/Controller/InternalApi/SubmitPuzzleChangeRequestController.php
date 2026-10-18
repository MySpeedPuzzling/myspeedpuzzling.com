<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\InternalApiInvalidInput;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\SubmitPuzzleChangeRequest;
use SpeedPuzzling\Web\Query\GetPendingPuzzleProposals;
use SpeedPuzzling\Web\Query\GetPuzzleRecord;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\ImagePipelineProposalRules;
use SpeedPuzzling\Web\Services\InternalApi\ProposedPuzzleImageInput;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleChangeRequestSource;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use SpeedPuzzling\Web\Value\PuzzleNames;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Files a change proposal for a puzzle found by an automated review (e.g. an EAN typed
 * without its zeros), so a moderator decides it like any player's "Suggest a change".
 * The reviewer player is the reporter. A field left out keeps the puzzle's current value,
 * so the review shows only what this proposal changes. The other names (`alternativeNames`, the whole
 * list as it should end up) and the main title's language (`nameLanguage`) are proposed together and
 * applied as a diff when approved (docs/features/puzzle-names/README.md).
 *
 * `image` (base64) proposes a new picture - checked and stored like the web form's photo (ProposedPuzzleImageInput).
 * `source: "image_pipeline"` files the image pipeline's proposal (docs/features/insights/README.md "Artwork crops") with
 * `tags`, `confidence`, `modelVersion` and `sourceImageFingerprint` (ImagePipelineProposalRules: one proposal per
 * picture and tag, ever; only for the puzzle's picture now; never for a hidden one).
 */
final class SubmitPuzzleChangeRequestController extends AbstractController
{
    private const int MAX_TAGS = 10;

    private const string TAG_PATTERN = '/^[A-Za-z0-9_.:-]{1,50}$/';

    private const int MAX_MODEL_VERSION_LENGTH = 100;

    private const string FINGERPRINT_PATTERN = '/^[0-9a-f]{' . PuzzleImageFingerprint::LENGTH . '}$/';

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly GetPuzzleRecord $getPuzzleRecord,
        private readonly GetPendingPuzzleProposals $getPendingPuzzleProposals,
        private readonly PuzzleRepository $puzzleRepository,
        private readonly ImagePipelineProposalRules $imagePipelineProposalRules,
        private readonly ProposedPuzzleImageInput $proposedPuzzleImageInput,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private readonly string $reviewerPlayerId,
    ) {
    }

    #[Route(
        path: '/internal-api/puzzle-change-requests',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        if ($this->reviewerPlayerId === '') {
            throw new BadRequestHttpException(
                'INTERNAL_API_REVIEWER_PLAYER_ID is not configured - no player to file the proposal as.',
            );
        }

        $body = InternalApiJsonBody::parse($request);
        $puzzleId = InternalApiJsonBody::requiredString($body, 'puzzleId');

        if (Uuid::isValid($puzzleId) === false) {
            throw new BadRequestHttpException('"puzzleId" must be an id.');
        }

        $puzzle = $this->getPuzzleRecord->byId($puzzleId) ?? throw new PuzzleNotFound();
        $metadata = self::metadata($body);
        $proposesImage = array_key_exists('image', $body) && $body['image'] !== null;

        // The rules of the image pipeline (a hidden picture, another picture now, proposed already) answer before
        // anything else, so a caller filing in bulk learns the real reason - the handler checks them again under the lock
        $this->imagePipelineProposalRules->check(
            $this->puzzleRepository->get($puzzleId),
            $metadata['source'],
            $metadata['sourceImageFingerprint'],
            $metadata['tags'],
            $proposesImage,
        );

        $name = InternalApiJsonBody::optionalString($body, 'name') ?? $puzzle->name;
        $manufacturerId = InternalApiJsonBody::optionalString($body, 'manufacturerId') ?? $puzzle->manufacturerId;
        // A list of codes or one comma-separated string; `[]` removes every code
        $eanInputs = InternalApiJsonBody::optionalCodeList($body, 'ean');
        $brandCodeInputs = InternalApiJsonBody::optionalCodeList($body, 'identificationNumber');
        $eans = $eanInputs !== null ? EanList::fromInputs($eanInputs) : EanList::fromStored($puzzle->ean);
        $brandCodes = $brandCodeInputs !== null ? BrandCodeList::fromInputs($brandCodeInputs) : BrandCodeList::fromStored($puzzle->identificationNumber);
        $piecesCount = $body['piecesCount'] ?? $puzzle->piecesCount;
        $alternativeNames = InternalApiJsonBody::optionalPuzzleNames($body, 'alternativeNames') ?? $puzzle->alternativeNames;
        $nameLanguage = InternalApiJsonBody::optionalLanguageTag($body, 'nameLanguage');
        $nameLanguage = $nameLanguage === false ? $puzzle->nameLanguage : $nameLanguage;

        if (is_int($piecesCount) === false || $piecesCount < 10 || $piecesCount > 25000) {
            throw new BadRequestHttpException('"piecesCount" must be a whole number from 10 to 25000.');
        }

        if ($manufacturerId !== null && Uuid::isValid($manufacturerId) === false) {
            throw new BadRequestHttpException('"manufacturerId" must be an id.');
        }

        if ($eans->fitsColumn() === false || $brandCodes->fitsColumn() === false) {
            throw new BadRequestHttpException('"ean" and "identificationNumber" can hold at most 255 characters each, written as a list.');
        }

        $invalidCodes = [];
        foreach ($eanInputs ?? [] as $eanInput) {
            $invalidCodes = [...$invalidCodes, ...EanList::invalidCodes($eanInput, $puzzle->ean)];
        }

        if ($invalidCodes !== []) {
            throw new BadRequestHttpException(sprintf(
                '"ean" holds codes that are not EAN/UPC codes: %s.',
                implode(', ', array_column($invalidCodes, 'code')),
            ));
        }

        // As the puzzle would keep them next to the proposed main title - an order of its own changes nothing
        $alternativeNames = $alternativeNames->cleanedFor($name);
        $namesChanged = $nameLanguage !== $puzzle->nameLanguage
            || $alternativeNames->diff($puzzle->alternativeNames)->isEmpty() === false;

        if ($namesChanged && count($alternativeNames) > PuzzleNames::FORM_MAX_NAMES && count($alternativeNames) > count($puzzle->alternativeNames)) {
            throw new BadRequestHttpException(sprintf('"alternativeNames" can hold at most %d names.', PuzzleNames::FORM_MAX_NAMES));
        }

        $otherChanges = $proposesImage
            || $manufacturerId !== $puzzle->manufacturerId
            || $piecesCount !== $puzzle->piecesCount
            || $eans->toStored() !== EanList::fromStored($puzzle->ean)->toStored()
            || $brandCodes->toStored() !== BrandCodeList::fromStored($puzzle->identificationNumber)->toStored();

        if ($name === $puzzle->name && $namesChanged === false && $otherChanges === false) {
            throw new BadRequestHttpException('Nothing to change - every given field equals the puzzle as it is.');
        }

        // Like the web form: one open proposal per puzzle - names only are filed regardless, they apply as a diff
        // (GetPendingPuzzleProposals). Answered without an exception, so a caller filing in bulk does not turn a known
        // conflict into a logged error.
        if ($otherChanges && $this->getPendingPuzzleProposals->blocksNewProposal($puzzleId)) {
            return new JsonResponse(
                [
                    'error' => 'The puzzle already has a pending change request (of more than its names) or merge request.',
                    'code' => 'pending_proposal',
                ],
                Response::HTTP_CONFLICT,
            );
        }

        $changeRequestId = Uuid::uuid7()->toString();
        // Decoded last - every other refusal comes first; a temporary file, removed once the message is handled
        $image = $proposesImage ? $this->proposedPuzzleImageInput->decode($body['image']) : null;

        try {
            $this->messageBus->dispatch(new SubmitPuzzleChangeRequest(
                changeRequestId: $changeRequestId,
                puzzleId: $puzzleId,
                reporterId: $this->reviewerPlayerId,
                proposedName: $name,
                proposedBrand: $manufacturerId,
                proposedPiecesCount: $piecesCount,
                proposedEans: $eans,
                proposedBrandCodes: $brandCodes,
                proposedPhoto: $image,
                originalAlternativeNames: $puzzle->alternativeNames,
                originalNameLanguage: $puzzle->nameLanguage,
                proposedAlternativeNames: $namesChanged ? $alternativeNames : null,
                proposedNameLanguage: $nameLanguage,
                source: $metadata['source'],
                tags: $metadata['tags'],
                confidence: $metadata['confidence'],
                modelVersion: $metadata['modelVersion'],
                sourceImageFingerprint: $metadata['sourceImageFingerprint'],
            ));
        } finally {
            ProposedPuzzleImageInput::remove($image);
        }

        // The record the proposal was filed against (to approve it only while the puzzle is still so), and the names
        // as filed when they are part of the proposal - cleaned the way the puzzle keeps them
        return new JsonResponse(['changeRequestId' => $changeRequestId, 'recordVersion' => $puzzle->recordVersion()] + ($namesChanged ? [
            'nameLanguage' => $nameLanguage,
            'alternativeNames' => $alternativeNames->toArray(),
        ] : []), Response::HTTP_CREATED);
    }

    /**
     * Who files it and what the image pipeline says about it - every field optional, except that the image pipeline
     * always names the picture it looked at and at least one tag (the "once per picture and tag" rule needs both).
     *
     * @param array<string, mixed> $body
     *
     * @return array{source: PuzzleChangeRequestSource, tags: list<string>, confidence: null|float, modelVersion: null|string, sourceImageFingerprint: null|string}
     */
    private static function metadata(array $body): array
    {
        $errors = [];

        $sourceValue = $body['source'] ?? null;
        $source = PuzzleChangeRequestSource::InternalApi;

        if ($sourceValue !== null) {
            $source = is_string($sourceValue) ? PuzzleChangeRequestSource::tryFrom($sourceValue) : null;

            if ($source === null || $source === PuzzleChangeRequestSource::Player) {
                $errors['source'] = 'must be "image_pipeline" or "internal_api" (the default).';
                $source = PuzzleChangeRequestSource::InternalApi;
            }
        }

        $tags = [];
        $tagsValue = $body['tags'] ?? null;

        if ($tagsValue !== null) {
            if (is_array($tagsValue) === false || array_is_list($tagsValue) === false || count($tagsValue) > self::MAX_TAGS) {
                $errors['tags'] = sprintf('must be a list of at most %d tags.', self::MAX_TAGS);
            } else {
                foreach ($tagsValue as $tag) {
                    if (is_string($tag) === false || preg_match(self::TAG_PATTERN, $tag) !== 1) {
                        $errors['tags'] = 'holds a tag that is not 1-50 characters of letters, digits, "_", "-", "." or ":".';

                        break;
                    }

                    $tags[] = $tag;
                }

                $tags = array_values(array_unique($tags));
            }
        }

        $confidence = $body['confidence'] ?? null;

        if ($confidence !== null && ((is_int($confidence) === false && is_float($confidence) === false) || $confidence < 0 || $confidence > 1)) {
            $errors['confidence'] = 'must be a number from 0 to 1.';
            $confidence = null;
        }

        $modelVersion = $body['modelVersion'] ?? null;

        if ($modelVersion !== null && (is_string($modelVersion) === false || trim($modelVersion) === '' || mb_strlen($modelVersion) > self::MAX_MODEL_VERSION_LENGTH || str_contains($modelVersion, "\0"))) {
            $errors['modelVersion'] = sprintf('must be a text of 1-%d characters.', self::MAX_MODEL_VERSION_LENGTH);
            $modelVersion = null;
        }

        $fingerprint = $body['sourceImageFingerprint'] ?? null;

        if ($fingerprint !== null && (is_string($fingerprint) === false || preg_match(self::FINGERPRINT_PATTERN, $fingerprint) !== 1)) {
            $errors['sourceImageFingerprint'] = 'must be the imageFingerprint of the image list (16 hex characters).';
            $fingerprint = null;
        }

        if ($source === PuzzleChangeRequestSource::ImagePipeline) {
            if (array_key_exists('sourceImageFingerprint', $body) === false || $body['sourceImageFingerprint'] === null) {
                $errors['sourceImageFingerprint'] = 'is required for "source": "image_pipeline" - the picture the proposal was made from.';
            }

            if (isset($errors['tags']) === false && $tags === []) {
                $errors['tags'] = 'needs at least one tag for "source": "image_pipeline" (e.g. "artwork_crop").';
            }
        }

        if ($errors !== []) {
            throw new InternalApiInvalidInput($errors);
        }

        return [
            'source' => $source,
            'tags' => $tags,
            'confidence' => $confidence !== null ? (float) $confidence : null,
            'modelVersion' => $modelVersion !== null ? trim($modelVersion) : null,
            'sourceImageFingerprint' => $fingerprint,
        ];
    }
}
