<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\InternalApi;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PuzzlesInternalApiTest extends WebTestCase
{
    use InternalApiRequests;

    public function testFindsAPuzzleByEan(): void
    {
        $browser = self::createClient();

        // Typed with a leading zero, like a GTIN-14 scanner reads it
        $answer = self::callInternalApi($browser, 'GET', '/internal-api/puzzles?ean=0' . PuzzleFixture::EAN_PUZZLE_300);

        self::assertResponseIsSuccessful();
        $puzzles = self::list($answer['puzzles']);
        self::assertSame(PuzzleFixture::PUZZLE_300, $puzzles[0]['puzzleId']);
        self::assertSame(300, $puzzles[0]['piecesCount']);
        self::assertSame('Ravensburger', $puzzles[0]['manufacturerName']);
        self::assertSame(PuzzleFixture::EAN_PUZZLE_300, $puzzles[0]['ean']);
        self::assertTrue($puzzles[0]['approved']);
        self::assertArrayHasKey('identificationNumber', $puzzles[0]);
    }

    public function testSearchesByNameAndBrand(): void
    {
        $browser = self::createClient();

        $byName = self::callInternalApi($browser, 'GET', '/internal-api/puzzles?q=Puzzle%203&brand=ravensburger');
        self::assertResponseIsSuccessful();
        self::assertContains(PuzzleFixture::PUZZLE_500_03, array_column(self::list($byName['puzzles']), 'puzzleId'));

        $byBrandId = self::callInternalApi($browser, 'GET', '/internal-api/puzzles?limit=100&brand=' . ManufacturerFixture::MANUFACTURER_TREFL);
        $brandNames = array_map(self::string(...), array_column(self::list($byBrandId['puzzles']), 'manufacturerName'));
        self::assertNotEmpty($brandNames);
        self::assertSame(['Trefl'], array_values(array_unique($brandNames)));

        $unknownBrand = self::callInternalApi($browser, 'GET', '/internal-api/puzzles?q=Puzzle&brand=No%20Such%20Brand');
        self::assertSame(0, $unknownBrand['total']);

        self::callInternalApi($browser, 'GET', '/internal-api/puzzles?q=Puzzle&ean=4005556202027');
        self::assertResponseStatusCodeSame(400);
    }

    public function testCreatesAnApprovedPuzzleWithoutAPhoto(): void
    {
        $browser = self::createClient();

        $puzzle = self::callInternalApi($browser, 'POST', '/internal-api/puzzles', [
            'name' => 'Championship Puzzle 2026',
            'brand' => 'ravensburger',
            'piecesCount' => 500,
            'ean' => '4006381333931',
            'identificationNumber' => '12000123',
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertSame('Championship Puzzle 2026', $puzzle['name']);
        self::assertSame(500, $puzzle['piecesCount']);
        self::assertSame(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, $puzzle['manufacturerId']);
        self::assertSame('4006381333931', $puzzle['ean']);
        self::assertSame('12000123', $puzzle['identificationNumber']);
        self::assertTrue($puzzle['approved']);
        self::assertFalse($puzzle['brandCreated']);

        $database = self::getContainer()->get(Connection::class);
        $row = $database->fetchAssociative('SELECT image, approved_by_id, added_by_user_id FROM puzzle WHERE id = :id', ['id' => $puzzle['puzzleId']]);
        self::assertIsArray($row);
        self::assertNull($row['image']);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $row['approved_by_id']);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $row['added_by_user_id']);

        // The approval is a catalogue decision in the puzzle's history, made through the internal API
        $decision = $database->fetchAssociative(
            'SELECT action, source FROM puzzle_moderation_decision WHERE puzzle_id = :id',
            ['id' => $puzzle['puzzleId']],
        );
        self::assertSame(['action' => 'puzzle_approved', 'source' => 'internal_api'], $decision);

        $found = self::callInternalApi($browser, 'GET', '/internal-api/puzzles?ean=4006381333931');
        self::assertSame([$puzzle['puzzleId']], array_column(self::list($found['puzzles']), 'puzzleId'));
    }

    public function testATypedBrandNobodyKnowsBecomesANewBrand(): void
    {
        $browser = self::createClient();

        $puzzle = self::callInternalApi($browser, 'POST', '/internal-api/puzzles', [
            'name' => 'Nordic Lights',
            'brand' => 'Pusselbolaget',
            'piecesCount' => 1000,
        ]);

        self::assertResponseStatusCodeSame(201);
        self::assertTrue($puzzle['brandCreated']);
        self::assertSame('Pusselbolaget', $puzzle['manufacturerName']);

        $brandApproved = self::getContainer()->get(Connection::class)
            ->fetchOne('SELECT approved FROM manufacturer WHERE id = :id', ['id' => $puzzle['manufacturerId']]);
        self::assertFalse($brandApproved);

        $byId = self::callInternalApi($browser, 'POST', '/internal-api/puzzles', [
            'name' => 'Nordic Lights II',
            'manufacturerId' => $puzzle['manufacturerId'],
            'piecesCount' => 1000,
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame($puzzle['manufacturerId'], $byId['manufacturerId']);
        self::assertFalse($byId['brandCreated']);
    }

    public function testAKnownBarcodeIsAConflictUnlessAllowed(): void
    {
        $browser = self::createClient();
        $fields = ['name' => 'Puzzle 11 again', 'brand' => 'Ravensburger', 'piecesCount' => 300, 'ean' => PuzzleFixture::EAN_PUZZLE_300];

        $answer = self::callInternalApi($browser, 'POST', '/internal-api/puzzles', $fields);
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString(PuzzleFixture::PUZZLE_300, self::string($answer['error']));

        self::callInternalApi($browser, 'POST', '/internal-api/puzzles', $fields + ['allowDuplicateEan' => true]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testInvalidPuzzleIsRefused(): void
    {
        $browser = self::createClient();

        $answer = self::callInternalApi($browser, 'POST', '/internal-api/puzzles', [
            'piecesCount' => 3,
            'colour' => 'blue',
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertIsArray($answer['errors']);
        foreach (['name', 'piecesCount', 'brand', 'colour'] as $field) {
            self::assertArrayHasKey($field, $answer['errors'], $field);
        }

        $badBarcode = self::callInternalApi($browser, 'POST', '/internal-api/puzzles', [
            'name' => 'Bad Barcode',
            'brand' => 'Ravensburger',
            'piecesCount' => 500,
            'ean' => '4005556123456',
            'nameLanguage' => 'not a language',
        ]);
        self::assertResponseStatusCodeSame(400);
        self::assertIsArray($badBarcode['errors']);
        self::assertStringContainsString('4005556123456', self::string($badBarcode['errors']['ean'] ?? null));
        self::assertArrayHasKey('nameLanguage', $badBarcode['errors']);

        self::callInternalApi($browser, 'POST', '/internal-api/puzzles', [
            'name' => 'Unknown Brand Id',
            'manufacturerId' => '018d0002-0000-0000-0000-00000000ffff',
            'piecesCount' => 500,
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function list(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var list<array<string, mixed>> $value */
        return $value;
    }

    private static function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
