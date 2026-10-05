<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\FormData;

use SpeedPuzzling\Web\FormData\ProposePuzzleChangesFormData;
use SpeedPuzzling\Web\FormData\PuzzleNamesFormData;
use SpeedPuzzling\Web\Value\PuzzleNames;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class ProposePuzzleChangesFormDataTest extends KernelTestCase
{
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->validator = self::getContainer()->get(ValidatorInterface::class);
    }

    public function testEveryInvalidCodeIsReportedOnItsOwnInput(): void
    {
        $data = $this->formData(eans: ['4005555011897', '45555011897', '', '4005555011898'], currentEan: '4005555011897');

        self::assertSame([
            'eans[1]' => ['"45555011897" looks like 4005555011897 with two zeros missing. Please use the full code from under the barcode.'],
            'eans[3]' => ['"4005555011898" is not a valid EAN or UPC code. Copy the digits under the barcode on the box - one code per field.'],
        ], $this->violations($data));
    }

    public function testInvalidCodeAlreadyOnThePuzzleDoesNotBlockOtherChanges(): void
    {
        $data = $this->formData(eans: ['17399'], currentEan: '17399');

        self::assertSame([], $this->violations($data));
    }

    public function testACodeComesBackFromTheFormAsItWasShown(): void
    {
        // Stored as typed long ago: the inputs show "6255" and the UPC with its 12th digit - both pass as listed
        $data = $this->formData(eans: ['6255', '021081241953'], currentEan: '#6255, 0021081241953');

        self::assertSame([], $this->violations($data));
    }

    public function testAtMostTenCodesPerField(): void
    {
        $data = $this->formData(eans: array_fill(0, 11, ''), currentEan: null);

        self::assertArrayHasKey('eans', $this->violations($data));
    }

    public function testTheBrandCodesMustFitTheColumn(): void
    {
        $data = $this->formData(eans: [], currentEan: null);
        $data->brandCodes = array_map(static fn (int $i): string => str_repeat((string) $i, 40), range(0, 9));

        self::assertSame(['brandCodes'], array_keys($this->violations($data)));
    }

    public function testTheNamesAreValidatedWithTheForm(): void
    {
        $data = $this->formData(eans: ['4005555011897'], currentEan: null);
        $data->names->name = ' ';

        self::assertSame(['names.name' => ['The puzzle needs a main title.']], $this->violations($data));
    }

    /**
     * @param array<int, null|string> $eans
     */
    private function formData(array $eans, null|string $currentEan): ProposePuzzleChangesFormData
    {
        $data = new ProposePuzzleChangesFormData();
        $data->names = PuzzleNamesFormData::fromNames('Pets of Palm Springs', null, new PuzzleNames());
        $data->piecesCount = 500;
        $data->eans = $eans;
        $data->currentEan = $currentEan;

        return $data;
    }

    /**
     * @return array<string, list<string>>
     */
    private function violations(ProposePuzzleChangesFormData $data): array
    {
        $messages = [];
        foreach ($this->validator->validate($data) as $violation) {
            $messages[$violation->getPropertyPath()][] = (string) $violation->getMessage();
        }

        return $messages;
    }
}
