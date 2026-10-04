<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\FormData;

use SpeedPuzzling\Web\FormData\ProposePuzzleChangesFormData;
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

    public function testEveryInvalidCodeIsReportedOnTheEanField(): void
    {
        $data = $this->formData(ean: '4005555011897, 45555011897, 4005555011898', currentEan: '4005555011897');

        self::assertSame([
            'ean' => [
                '"45555011897" looks like 4005555011897 with two zeros missing. Please use the full code from under the barcode.',
                '"4005555011898" is not a valid EAN or UPC code. Copy the digits under the barcode on the box; separate several codes with a comma.',
            ],
        ], $this->violations($data));
    }

    public function testInvalidCodeAlreadyOnThePuzzleDoesNotBlockOtherChanges(): void
    {
        $data = $this->formData(ean: '17399', currentEan: '17399');

        self::assertSame([], $this->violations($data));
    }

    private function formData(string $ean, null|string $currentEan): ProposePuzzleChangesFormData
    {
        $data = new ProposePuzzleChangesFormData();
        $data->name = 'Pets of Palm Springs';
        $data->piecesCount = 500;
        $data->ean = $ean;
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
