<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\MyData;

use NumberFormatter;
use SpeedPuzzling\Web\Exceptions\CollectionItemNotFound;
use SpeedPuzzling\Web\Message\EditCollectionItemComment;
use SpeedPuzzling\Web\Message\SaveMyDataResultCell;
use SpeedPuzzling\Web\Query\GetMyDataResultContext;
use SpeedPuzzling\Web\Repository\CollectionItemRepository;
use SpeedPuzzling\Web\Results\CustomFieldsForm;
use SpeedPuzzling\Web\Results\MyData\MyDataCellSave;
use SpeedPuzzling\Web\Results\MyData\MyDataResultEdit;
use SpeedPuzzling\Web\Results\MyData\MyDataRowCustomFields;
use SpeedPuzzling\Web\Results\PlayerProfile;
use SpeedPuzzling\Web\Services\CustomFields\CustomFieldLabels;
use SpeedPuzzling\Web\Services\MyData\CustomFieldsOfMyDataRow;
use SpeedPuzzling\Web\Services\MyData\MyDataResultEditor;
use SpeedPuzzling\Web\Services\PuzzlingTimeFormatter;
use SpeedPuzzling\Web\Services\ResultDateFormatter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CustomValues;
use SpeedPuzzling\Web\Value\MyDataColumns;
use SpeedPuzzling\Web\Value\SolvingTime;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * One cell of the wide table of "My data" edited in place (docs/features/my-data.md "Editing in place"): ONE field
 * of one row, saved by the same code as the row sheet - `MyDataResultEditor` for a result's time, day, first try and
 * comment, the existing messages for a collection item's comment and for the player's own fields.
 *
 * Three-way: the request says what the cell showed when its editor opened (`from`). When the stored value is another
 * one by now, nothing is saved and the answer (409) carries the current value. For a result - saved as a whole row -
 * the compare happens under the result's lock, as part of the write (SaveMyDataResultCell). A result somebody else
 * registered is on is not edited here at all (its row sheet: one save, one notification to them). A refusal (422) carries the reason in
 * the row sheet's words; one that is a question (the first try is elsewhere, the same time saved already, a time far
 * off the player's own) says so (`row: true`) - it is answered in the row sheet, never here.
 *
 * Form-encoded, the stateless CSRF token in `X-CSRF-Token`. A value of an own field travels as `my_fields[value]` /
 * `my_fields[from]`, the name OwnFieldsSentryScrubber withholds from every event.
 */
final class MyDataCellController extends AbstractController
{
    public function __construct(
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private MyDataResultEditor $editor,
        readonly private CustomFieldsOfMyDataRow $customFieldsOfMyDataRow,
        readonly private CollectionItemRepository $collectionItemRepository,
        readonly private GetMyDataResultContext $getMyDataResultContext,
        readonly private CustomFieldLabels $labels,
        readonly private PuzzlingTimeFormatter $timeFormatter,
        readonly private ResultDateFormatter $resultDateFormatter,
        readonly private MessageBusInterface $messageBus,
        readonly private CsrfTokenManagerInterface $csrfTokenManager,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/my-data/cell/{kind}/{id}',
        name: 'my_data_cell',
        requirements: ['_locale' => MyDataController::LOCALES, 'kind' => 'result|puzzle', 'id' => Requirement::UID_RFC4122],
        methods: ['POST'],
    )]
    #[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
    public function __invoke(Request $request, string $kind, string $id): Response
    {
        $viewer = $this->retrieveLoggedUserProfile->getProfile();
        assert($viewer !== null);

        if ($this->csrfTokenManager->isTokenValid(new CsrfToken(MyDataResultRowController::CSRF_TOKEN_ID, (string) $request->headers->get('X-CSRF-Token', ''))) === false) {
            return $this->refuse($this->translator->trans('my_data.edit.not_saved'), Response::HTTP_FORBIDDEN);
        }

        $column = $request->request->getString('column');
        $ownField = str_starts_with($column, MyDataColumns::fieldColumn(''));

        if ($ownField) {
            $own = $request->request->all()[CustomFieldsForm::INPUT] ?? null;
            $from = is_array($own) && is_string($own['from'] ?? null) ? $own['from'] : '';
            $value = is_array($own) && is_string($own['value'] ?? null) ? $own['value'] : '';
        } else {
            $from = $request->request->getString('from');
            $value = $request->request->getString('value');
        }

        $response = $kind === 'result'
            ? $this->resultCell($viewer, $id, $column, $ownField, $from, $value)
            : $this->puzzleCell($viewer, $id, $column, $ownField, $from, $value);

        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    private function resultCell(PlayerProfile $viewer, string $timeId, string $column, bool $ownField, string $from, string $value): JsonResponse
    {
        // Somebody else's result is not there at all
        $time = $this->editor->rowOf($viewer, $timeId);
        $timeId = $time->timeId;

        if ($ownField) {
            return $this->ownFieldCell(
                $column,
                $from,
                $value,
                fn (): MyDataRowCustomFields => $this->customFieldsOfMyDataRow->onResultOfSignedInViewer($timeId),
                fn (array $inputs): bool => $this->customFieldsOfMyDataRow->saveOnResultOfSignedInViewer($timeId, $inputs),
            );
        }

        if (in_array($column, MyDataResultEdit::FIELDS, true) === false) {
            return $this->refuse($this->translator->trans('my_data.edit.not_saved'));
        }

        // The compare with what the cell showed is part of the write: under the result's lock (SaveMyDataResultCell)
        $save = $this->messageBus->dispatch(new SaveMyDataResultCell($viewer, $timeId, $column, $from, $value))->last(HandledStamp::class)?->getResult();
        assert($save instanceof MyDataCellSave);

        return match ($save->status) {
            MyDataCellSave::READ_ONLY => $this->refuse($this->translator->trans('my_data.edit.read_only'), Response::HTTP_FORBIDDEN),
            // Edited through the row sheet: one save, one notification to the others on the result
            MyDataCellSave::IN_SHEET_ONLY => $this->refuse($this->translator->trans('my_data.edit.in_sheet_only'), Response::HTTP_FORBIDDEN, row: true),
            MyDataCellSave::CHANGED_MEANWHILE => $this->changedMeanwhile($this->resultCells($viewer, $timeId)[$column]),
            MyDataCellSave::INVALID => $this->refuse($this->translator->trans($column === MyDataResultEdit::TIME ? 'my_data.edit.time_invalid' : 'my_data.edit.not_saved')),
            // A time that needs the player's word is confirmed in the row's sheet - a table cell has nothing "below" it
            MyDataCellSave::REFUSED => $save->outcome?->firstError() === $this->translator->trans('suspicious_time.form.error')
                ? $this->refuse($this->translator->trans('my_data.edit.time_check_in_row'), row: true)
                : $this->refuse((string) $save->outcome?->firstError(), row: $save->outcome?->asksAQuestion === true),
            default => new JsonResponse(['status' => $save->status, 'cells' => $this->resultCells($viewer, $timeId)]),
        };
    }

    private function puzzleCell(PlayerProfile $viewer, string $itemId, string $column, bool $ownField, string $from, string $value): JsonResponse
    {
        $item = $this->collectionItemRepository->get($itemId);

        // Somebody else's puzzle is not there at all
        if ($item->player->id->toString() !== $viewer->playerId) {
            throw new CollectionItemNotFound();
        }

        $itemId = $item->id->toString();

        if ($ownField) {
            return $this->ownFieldCell(
                $column,
                $from,
                $value,
                fn (): MyDataRowCustomFields => $this->customFieldsOfMyDataRow->onCollectionItemOfSignedInViewer($itemId),
                fn (array $inputs): bool => $this->customFieldsOfMyDataRow->saveOnCollectionItemOfSignedInViewer($itemId, $inputs),
            );
        }

        if ($column !== MyDataResultEdit::COMMENT) {
            return $this->refuse($this->translator->trans('my_data.edit.not_saved'));
        }

        $stored = trim($item->comment ?? '');

        if (self::same($stored, $from) === false) {
            return $this->changedMeanwhile(['value' => $stored, 'text' => $stored]);
        }

        $comment = trim($value);
        // The comment form's own limit, in its words
        $tooLong = MyDataPuzzleRowController::commentRefusal($comment, $this->translator);

        if ($tooLong !== null) {
            return $this->refuse($tooLong);
        }

        if ($comment !== $stored) {
            $this->messageBus->dispatch(new EditCollectionItemComment(
                collectionItemId: $itemId,
                playerId: $viewer->playerId,
                comment: $comment !== '' ? $comment : null,
            ));
        }

        return new JsonResponse([
            'status' => $comment !== $stored ? 'saved' : 'unchanged',
            'cells' => [MyDataResultEdit::COMMENT => ['value' => $comment, 'text' => $comment]],
        ]);
    }

    /**
     * @param callable(): MyDataRowCustomFields $read the signed-in player's own fields and values of the row
     * @param callable(array<string, string>): bool $save
     */
    private function ownFieldCell(string $column, string $from, string $value, callable $read, callable $save): JsonResponse
    {
        $own = $read();
        $field = $own->fieldOfColumn($column);

        if ($field === null) {
            return $this->refuse($this->translator->trans('my_fields.errors.unknown_field'));
        }

        $stored = $own->storedInputs()[$field->fieldId];
        $cell = fn (MyDataRowCustomFields $fields): array => [
            'value' => $fields->storedInputs()[$field->fieldId] ?? '',
            'text' => $this->labels->valueText($field, $fields->stored->of($field->fieldId)),
        ];

        if (self::same($stored, $from) === false) {
            return $this->changedMeanwhile($cell($own));
        }

        $inputs = [$field->fieldId => $value];
        $problem = $own->problems($inputs)[$field->fieldId] ?? null;

        if ($problem !== null) {
            return $this->refuse($this->translator->trans($problem->messageKey(), [
                '%limit%' => match ($problem->value) {
                    'too_long' => CustomValues::TEXT_MAX_LENGTH,
                    'too_many_tags' => CustomValues::MAX_TAGS,
                    default => CustomValues::TAG_MAX_LENGTH,
                },
            ]));
        }

        if ($save($inputs) === false) {
            return $this->refuse($this->translator->trans('my_data.edit.not_saved'));
        }

        return new JsonResponse(['status' => 'saved', 'cells' => [$column => $cell($read())]]);
    }

    /**
     * The cells of one result the table shows from its editable fields, as stored now - worded like the page words
     * them (templates/my_data/_results.html.twig).
     *
     * @return array<string, array{value: string, text: string, badge?: null|string}>
     */
    private function resultCells(PlayerProfile $viewer, string $timeId): array
    {
        $time = $this->editor->rowOf($viewer, $timeId);
        $stored = MyDataResultEdit::ofStored($time);
        $context = $this->getMyDataResultContext->ofResult($timeId, $viewer->playerId);
        $date = $time->finishedAt ?? $time->trackedAt;
        $ppm = $time->time !== null && $time->time > 0
            ? new SolvingTime($time->time)->calculatePpm($time->piecesCount, 1 + count($time->players ?? []))
            : null;
        $numbers = new NumberFormatter($this->translator->getLocale(), NumberFormatter::DECIMAL);
        $numbers->setAttribute(NumberFormatter::FRACTION_DIGITS, 2);

        return [
            MyDataResultEdit::TIME => [
                'value' => $stored->cell(MyDataResultEdit::TIME),
                'text' => $time->time !== null ? $this->timeFormatter->formatTime($time->time) : $this->translator->trans('my_data.cell.no_time'),
                'badge' => $context['needsVerification'] ? $this->translator->trans('my_data.cell.needs_verification') : null,
            ],
            MyDataResultEdit::DATE => [
                'value' => $stored->cell(MyDataResultEdit::DATE),
                'text' => $date !== null ? $this->resultDateFormatter->format($date, $time->finishedAt !== null ? $time->datePrecision : null) : '',
            ],
            MyDataResultEdit::FIRST_TRY => [
                'value' => $stored->cell(MyDataResultEdit::FIRST_TRY),
                'text' => $time->firstAttempt ? $this->translator->trans('my_data.cell.ticked') : '',
            ],
            MyDataResultEdit::COMMENT => [
                'value' => trim($stored->comment),
                'text' => trim($stored->comment),
            ],
            'ppm' => [
                'value' => '',
                'text' => $ppm !== null ? (string) $numbers->format($ppm) : '',
            ],
        ];
    }

    /**
     * What a cell showed against what is stored - line ends and outer spaces are the browser's business.
     */
    private static function same(string $stored, string $from): bool
    {
        return trim(str_replace("\r\n", "\n", $stored)) === trim(str_replace("\r\n", "\n", $from));
    }

    /**
     * @param array{value: string, text: string, badge?: null|string} $cell
     */
    private function changedMeanwhile(array $cell): JsonResponse
    {
        return new JsonResponse([
            'status' => 'changed',
            'message' => $this->translator->trans('my_data.edit.changed_meanwhile'),
            'cell' => $cell,
        ], Response::HTTP_CONFLICT);
    }

    private function refuse(string $message, int $status = Response::HTTP_UNPROCESSABLE_ENTITY, bool $row = false): JsonResponse
    {
        return new JsonResponse(['status' => 'refused', 'message' => $message, 'row' => $row], $status);
    }
}
