<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImportPerson;
use SpeedPuzzling\Web\Entity\ImportPersonDecision;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Exceptions\ImportPersonChoiceRefused;
use SpeedPuzzling\Web\Message\DecideImportPerson;
use SpeedPuzzling\Web\Query\GetImportPeople;
use SpeedPuzzling\Web\Repository\DataImportPersonRepository;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Repository\ImportPersonDecisionRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportPartnerEligibility;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\DataImport\ImportPersonChoice;
use SpeedPuzzling\Web\Value\DataImport\ImportPersonDecidedHow;
use SpeedPuzzling\Web\Value\DataImport\ImportPersonText;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Under the import's lock (docs/features/data-import/people.md "Answers"). Everything is checked before anything is
 * written:
 *
 * - the import is the player's, the person is one of it;
 * - "it is this player": **a player picked from the offered ones must be one of the importing player's own people**
 *   (GetImportPeople::suggestable() - what the page offered: a co-member or a favourite, no block either way, not a
 *   private player who has not let them in) - a posted id of anybody else is refused; **a typed code** names any
 *   player the add form would take (ImportPartnerEligibility: the code exists, no block either way). A code nobody
 *   has and a code of a player with a block are refused with the same reason;
 * - "that is me" needs nothing but the importing player's word (a nickname the file uses for them).
 *
 * Then the person takes the answer, and the answer is remembered for the same name in the player's next file - where
 * a remembered player or "me" is **offered, not applied**: only "yes, still right" (`asRemembered`, or
 * `allRemembered` for every such name at once) takes it, as the importing player's word in that import. Rows that
 * are added already keep their results as they are.
 */
#[AsMessageHandler]
readonly final class DecideImportPersonHandler
{
    public function __construct(
        private DataImportRepository $dataImportRepository,
        private DataImportPersonRepository $personRepository,
        private ImportPersonDecisionRepository $decisionRepository,
        private ImportPartnerEligibility $eligibility,
        private GetImportPeople $getImportPeople,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws DataImportNotFound
     * @throws ImportPersonChoiceRefused
     */
    public function __invoke(DecideImportPerson $message): void
    {
        if (Uuid::isValid($message->importId) === false) {
            throw new DataImportNotFound();
        }

        $this->idLock->lockUntilCommit(Uuid::fromString($message->importId));
        $import = $this->dataImportRepository->getOwnedBy($message->importId, $message->playerId);
        $importer = $import->player;
        $now = $this->clock->now();

        // "Yes for all remembered": each name with an earlier answer of a player or "me" and nothing decided here -
        // those whose player may still be added; the others stay guests
        if ($message->allRemembered) {
            $waiting = array_filter(
                $this->personRepository->ofImport($import),
                static fn (DataImportPerson $person): bool => $person->isUndecided() && $person->writtenAsPlayer === false,
            );
            $answers = $this->decisionRepository->ofPlayerByKeys($importer, array_values(array_map(static fn (DataImportPerson $person): string => $person->personKey, $waiting)));

            foreach ($waiting as $person) {
                $answer = $answers[$person->personKey] ?? null;
                $taken = $answer === null ? null : $this->remembered($importer, $answer);

                if ($taken !== null) {
                    $person->decide($taken[0], $taken[1], ImportPersonDecidedHow::Player, $now);
                }
            }

            return;
        }

        $person = $this->personRepository->find($message->personId);

        if ($person === null || $person->import->id->equals($import->id) === false) {
            throw new ImportPersonChoiceRefused(ImportPersonChoiceRefused::UNKNOWN_PERSON);
        }

        if ($message->asRemembered) {
            $answer = $this->decisionRepository->ofPlayerByKeys($importer, [$person->personKey])[$person->personKey] ?? null;
            $taken = $answer === null ? null : $this->remembered($importer, $answer);

            if ($taken === null) {
                throw new ImportPersonChoiceRefused(ImportPersonChoiceRefused::UNKNOWN_PLAYER);
            }

            $person->decide($taken[0], $taken[1], ImportPersonDecidedHow::Player, $now);

            return;
        }

        $linked = null;

        if ($message->choice === ImportPersonChoice::Player) {
            if ($message->code !== null && trim($message->code) !== '') {
                $text = ImportPersonText::of(str_starts_with(trim($message->code), '#') ? $message->code : '#' . trim($message->code));
                $linked = $text?->code === null ? null : $this->eligibility->byCode($importer, $text->code);
            } elseif ($message->targetPlayerId !== null && Uuid::isValid($message->targetPlayerId)) {
                $offered = $this->getImportPeople->suggestable($importer->id->toString());
                $linked = isset($offered[strtolower($message->targetPlayerId)])
                    ? $this->eligibility->byId($importer, $message->targetPlayerId)
                    : null;
            } else {
                throw new ImportPersonChoiceRefused(ImportPersonChoiceRefused::INVALID);
            }

            if ($linked === null) {
                throw new ImportPersonChoiceRefused(ImportPersonChoiceRefused::UNKNOWN_PLAYER);
            }
        }

        $choice = $message->choice;

        // Their own code typed for a partner: it is them
        if ($linked !== null && $linked->id->equals($importer->id)) {
            $choice = ImportPersonChoice::Me;
            $linked = null;
        }

        $person->decide($choice, $linked, ImportPersonDecidedHow::Player, $now);

        $remembered = $this->decisionRepository->ofPlayerByKeys($importer, [$person->personKey])[$person->personKey] ?? null;

        if ($remembered === null) {
            $this->decisionRepository->save(new ImportPersonDecision(Uuid::uuid7(), $importer, $person->personKey, $choice, $linked, $now));
        } else {
            $remembered->decide($choice, $linked, $now);
        }
    }

    /**
     * An earlier answer as it can be taken now: "me", or the player while they may still be added - null: nothing
     * to take (an earlier "guest" is applied when the people are built; a player who may not be added is no answer).
     *
     * @return null|array{ImportPersonChoice, null|Player}
     */
    private function remembered(Player $importer, ImportPersonDecision $answer): null|array
    {
        if ($answer->choice === ImportPersonChoice::Me) {
            return [ImportPersonChoice::Me, null];
        }

        if ($answer->choice !== ImportPersonChoice::Player || $answer->linkedPlayer === null) {
            return null;
        }

        $linked = $this->eligibility->byId($importer, $answer->linkedPlayer->id->toString());

        return $linked === null || $linked->id->equals($importer->id) ? null : [ImportPersonChoice::Player, $linked];
    }
}
