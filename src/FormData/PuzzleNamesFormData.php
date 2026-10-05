<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Value\PuzzleName;
use SpeedPuzzling\Web\Value\PuzzleNames;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Valid;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Every name of a puzzle as the names editor (PuzzleNamesType, templates/puzzle/_names_editor.html.twig) edits them:
 * the main title, its language when the box has no English title, and the other names, each with its language.
 * Validated like Puzzle::changeNames() and PuzzleNames::assertFormLimits() check them; names folding equal are no
 * error - the puzzle keeps one of them.
 */
#[Callback('validate')]
final class PuzzleNamesFormData
{
    #[Length(max: PuzzleNames::MAX_NAME_LENGTH, maxMessage: 'puzzle_names.name_too_long')]
    public null|string $name = null;

    // The main title's language - null = English, or not known
    public null|string $nameLanguage = null;

    /**
     * @var list<PuzzleNameFormData>
     */
    #[Valid]
    public array $alternativeNames = [];

    /**
     * Not a form field: how many other names the puzzle had when the form was loaded - a merge may have left more than
     * a form may add, and removing one of them must still work.
     */
    public int $loadedAlternativeNamesCount = 0;

    public static function fromNames(string $name, null|string $nameLanguage, PuzzleNames $alternativeNames): self
    {
        $data = new self();
        $data->name = $name;
        $data->nameLanguage = $nameLanguage;
        $data->alternativeNames = array_map(
            static fn (PuzzleName $otherName): PuzzleNameFormData => new PuzzleNameFormData($otherName->name, $otherName->language),
            $alternativeNames->all(),
        );
        $data->loadedAlternativeNamesCount = count($alternativeNames);

        return $data;
    }

    public function mainTitle(): string
    {
        return trim($this->name ?? '');
    }

    /**
     * The other names in the editor's order, rows without a name left out - the entity cleans and merges the rest.
     */
    public function toPuzzleNames(): PuzzleNames
    {
        $names = [];

        foreach ($this->alternativeNames as $row) {
            $name = trim($row->name ?? '');

            if ($name !== '') {
                $names[] = new PuzzleName($name, $row->language !== null && $row->language !== '' ? $row->language : null);
            }
        }

        return new PuzzleNames($names);
    }

    public function validate(ExecutionContextInterface $context): void
    {
        // What Puzzle::changeNames() stores - spaces and control characters alone are no title
        if (PuzzleNames::cleanName($this->name ?? '') === '') {
            $context->buildViolation('puzzle_names.main_title_required')
                ->atPath('name')
                ->addViolation();
        }

        $count = $this->toPuzzleNames()->count();

        // Only a growing list can break the cap (PuzzleRecordUpdater)
        if ($count > PuzzleNames::FORM_MAX_NAMES && $count > $this->loadedAlternativeNamesCount) {
            $context->buildViolation('puzzle_names.too_many_names')
                ->setParameter('%limit%', (string) PuzzleNames::FORM_MAX_NAMES)
                ->atPath('alternativeNames')
                ->addViolation();
        }
    }
}
