<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Exceptions\PuzzleChangedMeanwhile;
use SpeedPuzzling\Web\Query\GetPuzzleApprovals;
use SpeedPuzzling\Web\Query\GetPuzzleChangeRequests;
use SpeedPuzzling\Web\Query\GetPuzzleRecord;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleReportFixture;
use SpeedPuzzling\Web\Value\PuzzleName;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\PuzzleRecordVersion;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class PuzzleRecordVersionTest extends KernelTestCase
{
    /**
     * The forms read the version from a query, the handlers from the entity - they must agree
     */
    public function testEveryFormReadsTheVersionTheHandlerComputes(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        // Names in several languages, a tag outside the list (a secret puzzle - hide_image_until ahead - is out of every
        // review until its reveal: SecretPuzzleAccessTest)
        $this->changePuzzle(PuzzleFixture::PUZZLE_500_01, static function (Puzzle $puzzle): void {
            $puzzle->changeNames('Kruh barev: Mušle', 'cs', new PuzzleNames([
                new PuzzleName('Seashells', 'en'),
                new PuzzleName('Muscheln', 'de'),
                new PuzzleName('Conchas', 'pt-BR'),
                new PuzzleName('Untagged', null),
            ]), new DateTimeImmutable());
        });

        $puzzle = $container->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_500_01);
        $version = PuzzleRecordVersion::ofPuzzle($puzzle);

        $record = $container->get(GetPuzzleRecord::class)->byId(PuzzleFixture::PUZZLE_500_01);
        self::assertNotNull($record);
        self::assertSame('cs', $record->nameLanguage);
        self::assertSame($version, $record->recordVersion());

        $approval = $container->get(GetPuzzleApprovals::class)->byPuzzleId(PuzzleFixture::PUZZLE_500_01);
        self::assertNotNull($approval);
        self::assertSame($version, $approval->recordVersion());

        $changeRequest = $container->get(GetPuzzleChangeRequests::class)->byId(PuzzleReportFixture::CHANGE_REQUEST_PENDING);
        self::assertNotNull($changeRequest);
        self::assertSame('cs', $changeRequest->puzzleNameLanguage);
        self::assertSame($version, $changeRequest->puzzleRecordVersion);

        PuzzleRecordVersion::assertUnchanged($puzzle, $version);
        PuzzleRecordVersion::assertUnchanged($puzzle, null);
    }

    public function testEveryPartOfTheRecordChangesTheVersion(): void
    {
        $version = self::version();

        self::assertSame(16, strlen($version));
        self::assertSame($version, self::version(manufacturerId: '018D0001-0000-0000-0000-000000000001'));

        $changed = [
            'name' => self::version(name: 'Seashell'),
            'nameLanguage' => self::version(nameLanguage: 'en'),
            'alternativeNames' => self::version(alternativeNames: new PuzzleNames([new PuzzleName('Mušle', null)])),
            'manufacturerId' => self::version(manufacturerId: null),
            'piecesCount' => self::version(piecesCount: 1000),
            'ean' => self::version(ean: null),
            'identificationNumber' => self::version(identificationNumber: '14710'),
            'image' => self::version(image: 'seashells-2.jpg'),
        ];

        foreach ($changed as $field => $changedVersion) {
            self::assertNotSame($version, $changedVersion, $field);
        }

        // The order of the other names is part of the record - the first one in a language is the one shown
        self::assertNotSame(
            self::version(alternativeNames: new PuzzleNames([new PuzzleName('A', 'cs'), new PuzzleName('B', 'cs')])),
            self::version(alternativeNames: new PuzzleNames([new PuzzleName('B', 'cs'), new PuzzleName('A', 'cs')])),
        );
    }

    public function testAStaleVersionIsRefused(): void
    {
        self::bootKernel();
        $puzzle = self::getContainer()->get(PuzzleRepository::class)->get(PuzzleFixture::PUZZLE_500_02);
        $loaded = PuzzleRecordVersion::ofPuzzle($puzzle);

        $puzzle->piecesCount = 520;

        $this->expectException(PuzzleChangedMeanwhile::class);
        PuzzleRecordVersion::assertUnchanged($puzzle, $loaded);
    }

    public function testEveryPuzzleChangeLocksThePuzzleUnderOneKey(): void
    {
        self::assertSame('puzzle-018d0003-0000-0000-0000-00000000000a', PuzzleRecordVersion::lockKey('018D0003-0000-0000-0000-00000000000A'));
    }

    private static function version(
        string $name = 'Seashells',
        null|string $nameLanguage = null,
        null|PuzzleNames $alternativeNames = null,
        null|string $manufacturerId = '018d0001-0000-0000-0000-000000000001',
        int $piecesCount = 500,
        null|string $ean = '4005556147090',
        null|string $identificationNumber = '14709',
        null|string $image = 'seashells.jpg',
    ): string {
        return PuzzleRecordVersion::of(
            name: $name,
            nameLanguage: $nameLanguage,
            alternativeNames: $alternativeNames ?? new PuzzleNames([new PuzzleName('Mušle', 'cs')]),
            manufacturerId: $manufacturerId,
            piecesCount: $piecesCount,
            ean: $ean,
            identificationNumber: $identificationNumber,
            image: $image,
        );
    }

    /**
     * @param callable(Puzzle): void $change
     */
    private function changePuzzle(string $puzzleId, callable $change): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $puzzle = $entityManager->find(Puzzle::class, $puzzleId);
        self::assertNotNull($puzzle);

        $change($puzzle);
        $entityManager->flush();
        $entityManager->clear();
    }
}
