<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\ManufacturerSlugRedirect;
use SpeedPuzzling\Web\Entity\Puzzle;
use SpeedPuzzling\Web\Entity\PuzzleChangeRequest;
use SpeedPuzzling\Web\Entity\PuzzleModerationDecision;
use SpeedPuzzling\Web\Exceptions\InvalidManufacturerMerge;
use SpeedPuzzling\Web\Exceptions\ManufacturerNameTaken;
use SpeedPuzzling\Web\Message\MergeManufacturers;
use SpeedPuzzling\Web\Services\GenerateManufacturerSlug;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleReportFixture;
use SpeedPuzzling\Web\Value\MergeDecisionConfidence;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class MergeManufacturersHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testMovesEverythingOntoTheSurvivorAndDeletesTheDuplicate(): void
    {
        $ravensburgerPuzzles = $this->puzzleIdsOf(ManufacturerFixture::MANUFACTURER_RAVENSBURGER);
        self::assertNotSame([], $ravensburgerPuzzles);

        $this->merge(
            survivor: ManufacturerFixture::MANUFACTURER_TREFL,
            merged: [ManufacturerFixture::MANUFACTURER_RAVENSBURGER],
            confidence: MergeDecisionConfidence::High,
            note: 'Same GS1 prefix',
        );

        self::assertNull($this->manufacturer(ManufacturerFixture::MANUFACTURER_RAVENSBURGER));

        foreach ($ravensburgerPuzzles as $puzzleId) {
            self::assertSame(
                ManufacturerFixture::MANUFACTURER_TREFL,
                $this->entityManager->find(Puzzle::class, $puzzleId)?->manufacturer?->id->toString(),
            );
        }

        // The change request proposing the merged brand now proposes the survivor
        $changeRequest = $this->entityManager->find(PuzzleChangeRequest::class, PuzzleReportFixture::CHANGE_REQUEST_WITH_IMAGE);
        self::assertSame(ManufacturerFixture::MANUFACTURER_TREFL, $changeRequest?->proposedManufacturer?->id->toString());

        $survivor = $this->manufacturer(ManufacturerFixture::MANUFACTURER_TREFL);
        self::assertNotNull($survivor);
        self::assertSame('Trefl', $survivor->name);
        self::assertSame('trefl', $survivor->slug, 'The survivor keeps its own address');
        self::assertSame('5900511, 4005556', $survivor->eanPrefix, 'Both company prefixes are kept');

        $redirect = $this->entityManager->find(ManufacturerSlugRedirect::class, 'ravensburger');
        self::assertSame(ManufacturerFixture::MANUFACTURER_TREFL, $redirect?->manufacturer->id->toString());

        $decisions = $this->decisions();
        self::assertCount(1, $decisions);
        self::assertSame(MergeDecisionSource::InternalApi, $decisions[0]->source);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $decisions[0]->decidedById->toString());
        self::assertSame(ManufacturerFixture::MANUFACTURER_TREFL, $decisions[0]->manufacturerId?->toString());
        self::assertSame('Same GS1 prefix', $decisions[0]->note);
        self::assertSame('Ravensburger', $decisions[0]->details['mergedManufacturerName'] ?? null);
        self::assertSame('ravensburger', $decisions[0]->details['mergedManufacturerSlug'] ?? null);
        self::assertSame(count($ravensburgerPuzzles), $decisions[0]->details['movedPuzzles'] ?? null);
        self::assertSame(1, $decisions[0]->details['movedChangeRequests'] ?? null);
        self::assertSame('high', $decisions[0]->details['decisionConfidence'] ?? null);
    }

    public function testRenamesTheSurvivorAndRecordsOneDecisionPerMergedBrand(): void
    {
        $copy = $this->createBrand('  trefl ', approved: false);

        $this->merge(
            survivor: ManufacturerFixture::MANUFACTURER_TREFL,
            merged: [ManufacturerFixture::MANUFACTURER_UNAPPROVED, $copy],
            name: 'TREFL',
        );

        $survivor = $this->manufacturer(ManufacturerFixture::MANUFACTURER_TREFL);
        self::assertSame('TREFL', $survivor?->name);
        self::assertSame('trefl', $survivor->slug);
        self::assertNull($this->manufacturer(ManufacturerFixture::MANUFACTURER_UNAPPROVED));
        self::assertNull($this->manufacturer($copy));

        $decisions = $this->decisions();
        self::assertCount(2, $decisions);
        self::assertSame('Trefl', $decisions[0]->details['intoManufacturerPreviousName'] ?? null);
    }

    public function testAnUnapprovedSurvivorOfAnApprovedBrandIsApproved(): void
    {
        $this->merge(
            survivor: ManufacturerFixture::MANUFACTURER_UNAPPROVED,
            merged: [ManufacturerFixture::MANUFACTURER_TREFL],
        );

        self::assertTrue($this->manufacturer(ManufacturerFixture::MANUFACTURER_UNAPPROVED)?->approved);
        self::assertTrue($this->decisions()[0]->details['approvedByMerge'] ?? null);
    }

    public function testRedirectsOfAMergedBrandMoveToTheNewSurvivor(): void
    {
        $this->merge(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, [ManufacturerFixture::MANUFACTURER_TREFL]);
        $this->merge(ManufacturerFixture::MANUFACTURER_UNAPPROVED, [ManufacturerFixture::MANUFACTURER_RAVENSBURGER]);

        foreach (['trefl', 'ravensburger'] as $slug) {
            self::assertSame(
                ManufacturerFixture::MANUFACTURER_UNAPPROVED,
                $this->entityManager->find(ManufacturerSlugRedirect::class, $slug)?->manufacturer->id->toString(),
                sprintf('"%s" must point straight at the last survivor, never chain', $slug),
            );
        }
    }

    public function testAMergedSlugIsNeverGivenToANewBrand(): void
    {
        $this->merge(ManufacturerFixture::MANUFACTURER_RAVENSBURGER, [ManufacturerFixture::MANUFACTURER_TREFL]);

        self::assertSame('trefl-2', self::getContainer()->get(GenerateManufacturerSlug::class)->fromName('Trefl'));
    }

    public function testMergingTheSurvivorIntoItselfChangesNothing(): void
    {
        try {
            $this->merge(
                survivor: ManufacturerFixture::MANUFACTURER_TREFL,
                merged: [ManufacturerFixture::MANUFACTURER_UNAPPROVED, ManufacturerFixture::MANUFACTURER_TREFL],
            );
            self::fail('Expected the merge to be refused');
        } catch (InvalidManufacturerMerge) {
        }

        $this->entityManager->clear();
        self::assertNotNull($this->manufacturer(ManufacturerFixture::MANUFACTURER_UNAPPROVED));
        self::assertSame([], $this->decisions());
    }

    public function testARenameMustNotCollideWithAnotherApprovedBrand(): void
    {
        $this->expectException(ManufacturerNameTaken::class);

        $this->merge(
            survivor: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            merged: [ManufacturerFixture::MANUFACTURER_UNAPPROVED],
            name: 'trefl',
        );
    }

    /**
     * @param list<string> $merged
     */
    private function merge(
        string $survivor,
        array $merged,
        null|string $name = null,
        null|MergeDecisionConfidence $confidence = null,
        null|string $note = null,
    ): void {
        $this->messageBus->dispatch(new MergeManufacturers(
            survivorManufacturerId: $survivor,
            mergedManufacturerIds: $merged,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            decisionSource: MergeDecisionSource::InternalApi,
            survivorName: $name,
            decisionConfidence: $confidence,
            decisionNote: $note,
        ));

        $this->entityManager->clear();
    }

    private function createBrand(string $name, bool $approved): string
    {
        $id = Uuid::uuid7();

        $this->entityManager->persist(new Manufacturer(
            id: $id,
            name: $name,
            approved: $approved,
            addedAt: self::getContainer()->get(ClockInterface::class)->now(),
            slug: 'brand-' . $id->toString(),
        ));
        $this->entityManager->flush();

        return $id->toString();
    }

    /**
     * @return list<string>
     */
    private function puzzleIdsOf(string $manufacturerId): array
    {
        $puzzles = $this->entityManager->getRepository(Puzzle::class)->findBy(['manufacturer' => $manufacturerId]);

        return array_map(static fn (Puzzle $puzzle): string => $puzzle->id->toString(), $puzzles);
    }

    private function manufacturer(string $id): null|Manufacturer
    {
        return $this->entityManager->find(Manufacturer::class, $id);
    }

    /**
     * @return list<PuzzleModerationDecision>
     */
    private function decisions(): array
    {
        return $this->entityManager->getRepository(PuzzleModerationDecision::class)
            ->findBy(['action' => PuzzleModerationAction::BrandMerged], ['decidedAt' => 'ASC']);
    }
}
