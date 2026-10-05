<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use Symfony\Component\Validator\Constraints\All;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * A puzzle's EANs and brand codes in a form that holds the puzzle's record: one input per code (CodeListType),
 * prefilled from EanList::display() / BrandCodeList::display(). Every new EAN needs a right check digit, the ones the
 * puzzle already carries pass as they are (EanList::invalidCodes()).
 */
trait PuzzleCodesFields
{
    /**
     * @var array<int, null|string>
     */
    #[Count(max: EanList::FORM_MAX_CODES)]
    #[All([new Length(max: 30)])]
    public array $eans = [];

    /**
     * The puzzle's EAN list as it is now (not a form field) - its codes pass even when invalid
     */
    public null|string $currentEan = null;

    /**
     * @var array<int, null|string>
     */
    #[Count(max: BrandCodeList::FORM_MAX_CODES)]
    #[All([new Length(max: 50)])]
    public array $brandCodes = [];

    public function eanList(): EanList
    {
        return EanList::fromInputs($this->eans);
    }

    public function brandCodeList(): BrandCodeList
    {
        return BrandCodeList::fromInputs($this->brandCodes);
    }

    /**
     * The codes as stored now in the inputs, and the current EAN list whose codes pass the check digit.
     */
    private function loadCodes(null|string $ean, null|string $brandCodes, null|string $currentEan): void
    {
        $this->eans = EanList::fromStored($ean)->display();
        $this->brandCodes = BrandCodeList::fromStored($brandCodes)->display();
        $this->currentEan = $currentEan;
    }

    private function validateCodes(ExecutionContextInterface $context): void
    {
        EanList::addInputViolations($context, 'eans', $this->eans, $this->currentEan);
        BrandCodeList::addViolations($context, 'brandCodes', $this->brandCodes);
    }
}
