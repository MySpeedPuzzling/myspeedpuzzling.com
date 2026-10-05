<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\EventSubscriber\InternalApiAuditSubscriber;
use SpeedPuzzling\Web\Message\AddApprovedPuzzle;
use SpeedPuzzling\Web\Query\FindPuzzlesByExactEan;
use SpeedPuzzling\Web\Query\GetAdminPuzzles;
use SpeedPuzzling\Web\Services\ManufacturerResolver;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\Ean;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleNames;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Adds a puzzle to the catalogue, approved and without a photo (AddApprovedPuzzle) - for a competition puzzle the site
 * does not know yet. The brand is an id (`manufacturerId`) or a name (`brand`): an existing brand of that name, else a
 * new unapproved brand (approve it with POST /internal-api/manufacturers/{id}/approve). A barcode another puzzle already
 * carries is refused (409, naming it) unless `allowDuplicateEan` is true - it is usually the same puzzle.
 */
final class CreatePuzzleController extends AbstractController
{
    private const array FIELDS = [
        'name',
        'nameLanguage',
        'alternativeNames',
        'brand',
        'manufacturerId',
        'piecesCount',
        'ean',
        'identificationNumber',
        'allowDuplicateEan',
    ];

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly ManufacturerResolver $manufacturerResolver,
        private readonly FindPuzzlesByExactEan $findPuzzlesByExactEan,
        private readonly GetAdminPuzzles $getAdminPuzzles,
        #[Autowire(env: 'INTERNAL_API_REVIEWER_PLAYER_ID')]
        private readonly string $reviewerPlayerId,
    ) {
    }

    #[Route(
        path: '/internal-api/puzzles',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        if ($this->reviewerPlayerId === '') {
            throw new BadRequestHttpException(
                'INTERNAL_API_REVIEWER_PLAYER_ID is not configured - no player to add the puzzle as.',
            );
        }

        $body = InternalApiJsonBody::parse($request);
        $input = new InternalApiInput($body, self::FIELDS);

        $name = $input->string('name', required: true, maxLength: PuzzleNames::MAX_NAME_LENGTH);
        $name = $name !== null ? PuzzleNames::cleanName($name) : null;
        $piecesCount = $input->int('piecesCount', required: true, minimum: 10, maximum: 25000);
        $brandName = $input->string('brand', maxLength: 255);
        $manufacturerId = $input->string('manufacturerId');
        $allowDuplicateEan = $input->bool('allowDuplicateEan') ?? false;

        if (($brandName === null) === ($manufacturerId === null)) {
            $input->addError('brand', 'send the brand either by id ("manufacturerId") or by name ("brand").');
        }

        if ($manufacturerId !== null && Uuid::isValid($manufacturerId) === false) {
            $input->addError('manufacturerId', 'must be an id.');
        }

        $input->throwIfInvalid();
        assert($name !== null && $piecesCount !== null);

        // The parsers the other catalogue endpoints use (400 on a bad value)
        $nameLanguage = InternalApiJsonBody::optionalLanguageTag($body, 'nameLanguage');
        $alternativeNames = (InternalApiJsonBody::optionalPuzzleNames($body, 'alternativeNames') ?? new PuzzleNames())->cleanedFor($name);
        $eanInputs = InternalApiJsonBody::optionalCodeList($body, 'ean') ?? [];
        $brandCodeInputs = InternalApiJsonBody::optionalCodeList($body, 'identificationNumber') ?? [];
        $eans = EanList::fromInputs($eanInputs);
        $brandCodes = BrandCodeList::fromInputs($brandCodeInputs);

        if (count($alternativeNames) > PuzzleNames::FORM_MAX_NAMES) {
            throw new BadRequestHttpException(sprintf('"alternativeNames" can hold at most %d names.', PuzzleNames::FORM_MAX_NAMES));
        }

        if ($eans->fitsColumn() === false || $brandCodes->fitsColumn() === false) {
            throw new BadRequestHttpException('"ean" and "identificationNumber" can hold at most 255 characters each, written as a list.');
        }

        $invalidCodes = [];
        foreach ($eanInputs as $eanInput) {
            $invalidCodes = [...$invalidCodes, ...EanList::invalidCodes($eanInput, null)];
        }

        if ($invalidCodes !== []) {
            throw new BadRequestHttpException(sprintf(
                '"ean" holds codes that are not EAN/UPC codes: %s.',
                implode(', ', array_column($invalidCodes, 'code')),
            ));
        }

        if ($allowDuplicateEan === false) {
            $this->refuseKnownBarcodes($eans);
        }

        $brand = $manufacturerId ?? $brandName;
        assert($brand !== null);
        // Read before the add: a typed name nothing matches becomes a new brand
        $brandCreated = $manufacturerId === null && $this->manufacturerResolver->findExisting($brand) === null;

        $puzzleId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddApprovedPuzzle(
            puzzleId: $puzzleId,
            reviewerId: $this->reviewerPlayerId,
            name: $name,
            brand: $brand,
            piecesCount: $piecesCount,
            eans: $eans,
            brandCodes: $brandCodes,
            nameLanguage: $nameLanguage === false ? null : $nameLanguage,
            alternativeNames: $alternativeNames,
        ));

        $request->attributes->set(InternalApiAuditSubscriber::CREATED_ID_ATTRIBUTE, $puzzleId->toString());

        $puzzle = $this->getAdminPuzzles->byIds([$puzzleId->toString()])[$puzzleId->toString()] ?? null;
        assert($puzzle !== null);

        return new JsonResponse([...$puzzle->toArray(), 'brandCreated' => $brandCreated], Response::HTTP_CREATED);
    }

    private function refuseKnownBarcodes(EanList $eans): void
    {
        $knownPuzzleIds = [];

        foreach ($eans->codes() as $code) {
            $ean = Ean::tryFrom($code);

            if ($ean !== null) {
                $knownPuzzleIds = [...$knownPuzzleIds, ...$this->findPuzzlesByExactEan->ids($ean)];
            }
        }

        if ($knownPuzzleIds !== []) {
            throw new ConflictHttpException(sprintf(
                'A puzzle with this EAN exists already: %s. Use it, or send "allowDuplicateEan": true if it really is another puzzle.',
                implode(', ', array_values(array_unique($knownPuzzleIds))),
            ));
        }
    }
}
