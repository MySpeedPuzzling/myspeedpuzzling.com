<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleChangeRequest;
use SpeedPuzzling\Web\Exceptions\InvalidPuzzleValues;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Exceptions\PuzzleNameAlreadyKnown;
use SpeedPuzzling\Web\Exceptions\PuzzleNotFound;
use SpeedPuzzling\Web\Message\SuggestPuzzleName;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Repository\PuzzleChangeRequestRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\PuzzleModerationDecisionRecorder;
use SpeedPuzzling\Web\Services\PuzzleRecordUpdater;
use SpeedPuzzling\Web\Value\LanguageTag;
use SpeedPuzzling\Web\Value\PuzzleImageChoice;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use SpeedPuzzling\Web\Value\PuzzleName;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\PuzzleRecordValues;
use SpeedPuzzling\Web\Value\SearchText;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * "Suggest another name" (docs/features/puzzle-names/README.md, "Writing names and codes"). The name is added to the
 * puzzle's other names - or, when the same name is there without a language, it tags that one. A player's suggestion
 * becomes a change request of the names only (applied as a diff when approved); a moderator's or an admin's is saved at
 * once through PuzzleRecordUpdater and recorded in the decision log as their direct edit, and may make the name the
 * main title. A name the puzzle has already is refused (PuzzleNameAlreadyKnown), before anything is changed.
 */
#[AsMessageHandler]
readonly final class SuggestPuzzleNameHandler
{
    public function __construct(
        private PuzzleRepository $puzzleRepository,
        private PlayerRepository $playerRepository,
        private PuzzleChangeRequestRepository $puzzleChangeRequestRepository,
        private PuzzleRecordUpdater $puzzleRecordUpdater,
        private PuzzleModerationDecisionRecorder $puzzleModerationDecisionRecorder,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws PuzzleNotFound
     * @throws PlayerNotFound
     * @throws PuzzleNameAlreadyKnown
     * @throws InvalidPuzzleValues
     */
    public function __invoke(SuggestPuzzleName $message): void
    {
        $puzzle = $this->puzzleRepository->get($message->puzzleId);
        $player = $this->playerRepository->get($message->playerId);

        $suggested = self::suggestedName($message);

        if ($player->isAdmin || $player->isModerator()) {
            $this->apply($puzzle, $player, $suggested, $message->makeMainTitle);

            return;
        }

        $alternativeNames = self::withSuggestedName($puzzle->alternativeNames(), $suggested, $puzzle->name);
        $alternativeNames->assertFormLimits(loadedCount: count($puzzle->alternativeNames()));

        $this->puzzleChangeRequestRepository->save(new PuzzleChangeRequest(
            id: Uuid::fromString($message->suggestionId),
            puzzle: $puzzle,
            reporter: $player,
            submittedAt: $this->clock->now(),
            proposedAlternativeNames: $alternativeNames->cleanedFor($puzzle->name)->toArray(),
            proposedNameLanguage: $puzzle->nameLanguage,
            originalName: $puzzle->name,
            originalManufacturerId: $puzzle->manufacturer?->id,
            originalPiecesCount: $puzzle->piecesCount,
            originalEan: $puzzle->ean,
            originalIdentificationNumber: $puzzle->identificationNumber,
            originalImage: $puzzle->image,
            originalAlternativeNames: $puzzle->alternativeNames,
            originalNameLanguage: $puzzle->nameLanguage,
        ));
    }

    /**
     * A moderator's or an admin's name, saved at once - the rest of the record as it is.
     */
    private function apply(Puzzle $puzzle, Player $player, PuzzleName $suggested, bool $makeMainTitle): void
    {
        if ($makeMainTitle) {
            [$name, $nameLanguage, $alternativeNames] = self::withNewMainTitle($puzzle, $suggested);
        } else {
            $name = $puzzle->name;
            $nameLanguage = $puzzle->nameLanguage;
            $alternativeNames = self::withSuggestedName($puzzle->alternativeNames(), $suggested, $puzzle->name);
        }

        // Validates every value before it changes anything
        $change = $this->puzzleRecordUpdater->update($puzzle, new PuzzleRecordValues(
            name: $name,
            nameLanguage: $nameLanguage,
            alternativeNames: $alternativeNames,
            manufacturerId: $puzzle->manufacturer?->id->toString(),
            piecesCount: $puzzle->piecesCount,
            ean: $puzzle->ean,
            identificationNumber: $puzzle->identificationNumber,
        ));

        $this->puzzleModerationDecisionRecorder->record(
            action: PuzzleModerationAction::PuzzleEdited,
            decidedBy: $player,
            puzzleId: $puzzle->id,
            puzzleName: $puzzle->name,
            details: ['image' => PuzzleImageChoice::Keep->value] + $change,
        );
    }

    /**
     * @throws InvalidPuzzleValues
     */
    private static function suggestedName(SuggestPuzzleName $message): PuzzleName
    {
        $name = PuzzleNames::cleanName($message->name);

        if ($name === '' || mb_strlen($name) > PuzzleNames::MAX_NAME_LENGTH) {
            throw new InvalidPuzzleValues(sprintf('The name must have 1 to %d characters.', PuzzleNames::MAX_NAME_LENGTH));
        }

        $language = $message->language !== null ? LanguageTag::normalize($message->language) : null;

        if ($message->language !== null && $language === null) {
            throw new InvalidPuzzleValues('Unknown language.');
        }

        return new PuzzleName($name, $language);
    }

    /**
     * The other names with the suggested one: added at the end, or tagging the same name that has no language yet.
     *
     * @throws PuzzleNameAlreadyKnown
     */
    private static function withSuggestedName(PuzzleNames $names, PuzzleName $suggested, string $mainTitle): PuzzleNames
    {
        $key = SearchText::fold($suggested->name);

        if ($key === SearchText::fold($mainTitle)) {
            throw new PuzzleNameAlreadyKnown();
        }

        $all = $names->all();

        foreach ($all as $index => $name) {
            if (SearchText::fold($name->name) !== $key) {
                continue;
            }

            if ($name->language !== null || $suggested->language === null) {
                throw new PuzzleNameAlreadyKnown();
            }

            $all[$index] = $suggested;

            return new PuzzleNames($all);
        }

        return new PuzzleNames([...$all, $suggested]);
    }

    /**
     * The suggested name as the main title (its language the main title's, unless English), the main title first of
     * the other names in the main title's language - like "Make main title" in the names editor.
     *
     * @return array{string, null|string, PuzzleNames}
     *
     * @throws PuzzleNameAlreadyKnown
     */
    private static function withNewMainTitle(Puzzle $puzzle, PuzzleName $suggested): array
    {
        $key = SearchText::fold($suggested->name);

        if ($key === SearchText::fold($puzzle->name)) {
            throw new PuzzleNameAlreadyKnown();
        }

        $others = array_filter(
            $puzzle->alternativeNames()->all(),
            static fn (PuzzleName $name): bool => SearchText::fold($name->name) !== $key,
        );

        return [
            $suggested->name,
            $suggested->language !== null && LanguageTag::base($suggested->language) === 'en' ? null : $suggested->language,
            new PuzzleNames([new PuzzleName($puzzle->name, $puzzle->nameLanguage), ...$others]),
        ];
    }
}
