<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use DateTimeZone;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\EventSubscriber\InternalApiAuditSubscriber;
use SpeedPuzzling\Web\Message\AddEventResultsSheet;
use SpeedPuzzling\Web\Query\GetEventResultsSheets;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use SpeedPuzzling\Web\Services\EventResultsImport\Format\ResultsFormats;
use SpeedPuzzling\Web\Services\EventResultsImport\Format\SarahDoesPuzzlesFormat;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Registers an organizer's results sheet (docs/features/event-results-import/README.md): `spreadsheet` = its link or
 * id, the `organizationId` its events land in, `actingPlayerId` = who on the organization's team they are created as,
 * `timezone` of the sheet's start times, optional `countryCode` (the organization's when left out), optional `format`
 * = the key of the ResultsFormat it is written in (`sarah_does_puzzles` when left out - the one format before formats).
 * Read on demand: POST …/{id}/sync, or myspeedpuzzling:sync-results-source.
 */
final class CreateEventResultsSheetController extends AbstractController
{
    private const string SPREADSHEET_PATTERN = '~^(?:https://docs\.google\.com/spreadsheets/(?:u/\d+/)?d/)?([A-Za-z0-9_-]{20,100})(?:[/?#].*)?$~';

    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly GetEventResultsSheets $getEventResultsSheets,
        private readonly OrganizationRepository $organizationRepository,
        private readonly ResultsFormats $formats,
    ) {
    }

    #[Route(
        path: '/internal-api/event-results-sheets',
        methods: ['POST'],
    )]
    public function __invoke(Request $request): JsonResponse
    {
        $input = InternalApiInput::fromRequest($request, ['spreadsheet', 'organizationId', 'actingPlayerId', 'timezone', 'countryCode', 'format']);

        $spreadsheet = $input->string('spreadsheet', required: true);
        $spreadsheetId = null;

        if ($spreadsheet !== null) {
            if (preg_match(self::SPREADSHEET_PATTERN, trim($spreadsheet), $match) === 1) {
                $spreadsheetId = $match[1];
            } else {
                $input->addError('spreadsheet', 'must be a Google Sheets link or a spreadsheet id.');
            }
        }

        $organizationId = $input->id('organizationId', required: true);
        $actingPlayerId = $input->id('actingPlayerId', required: true);

        $timezone = $input->string('timezone', required: true);

        if ($timezone !== null && in_array($timezone, DateTimeZone::listIdentifiers(), true) === false) {
            $input->addError('timezone', 'must be a time zone like "America/Chicago".');
        }

        $countryCode = $input->string('countryCode');

        if ($countryCode !== null && preg_match('/^[a-zA-Z]{2}$/', $countryCode) !== 1) {
            $input->addError('countryCode', 'must be two letters.');
        }

        $format = $input->string('format') ?? SarahDoesPuzzlesFormat::KEY;

        if ($this->formats->has($format) === false) {
            $input->addError('format', sprintf('must be one of: %s.', implode(', ', $this->formats->keys())));
        }

        $input->throwIfInvalid();
        assert($spreadsheetId !== null && $organizationId !== null && $actingPlayerId !== null && $timezone !== null);

        $countryCode ??= $this->organizationRepository->get($organizationId)->countryCode;

        if ($countryCode === null) {
            $input->addError('countryCode', 'is required - the organization has no country.');
            $input->throwIfInvalid();
        }

        assert($countryCode !== null);
        $sheetId = Uuid::uuid7();

        $this->messageBus->dispatch(new AddEventResultsSheet($sheetId, $spreadsheetId, $organizationId, $actingPlayerId, $timezone, $countryCode, $format));

        $request->attributes->set(InternalApiAuditSubscriber::CREATED_ID_ATTRIBUTE, $sheetId->toString());

        return new JsonResponse($this->getEventResultsSheets->detail($sheetId->toString()), Response::HTTP_CREATED);
    }
}
