<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\ManufacturerSlugRedirect;
use SpeedPuzzling\Web\Entity\PuzzleChangeRequest;
use SpeedPuzzling\Web\Entity\PuzzleModerationDecision;
use SpeedPuzzling\Web\Exceptions\ManufacturerInUse;
use SpeedPuzzling\Web\Message\DeleteManufacturer;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleReportFixture;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class DeleteManufacturerHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testDeletesAnEmptyBrandAndRecordsWhatItWas(): void
    {
        $brandId = $this->createEmptyBrand('Empty Copy', 'empty-copy');

        $this->delete($brandId, note: 'Typo of Trefl, nothing under it');

        self::assertNull($this->entityManager->find(Manufacturer::class, $brandId));

        $decisions = $this->decisions();
        self::assertCount(1, $decisions);
        self::assertSame(MergeDecisionSource::InternalApi, $decisions[0]->source);
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $decisions[0]->decidedById->toString());
        self::assertSame($brandId, $decisions[0]->manufacturerId?->toString());
        self::assertSame('Typo of Trefl, nothing under it', $decisions[0]->note);
        self::assertSame([
            'manufacturerName' => 'Empty Copy',
            'manufacturerSlug' => 'empty-copy',
            'manufacturerApproved' => false,
            'manufacturerAddedAt' => '2026-09-25T10:00:00+00:00',
        ], $decisions[0]->details);
    }

    public function testABrandWithPuzzlesIsRefused(): void
    {
        $this->assertRefused(ManufacturerFixture::MANUFACTURER_TREFL);
    }

    public function testABrandAChangeRequestProposesIsRefused(): void
    {
        $brandId = $this->createEmptyBrand('Proposed Brand', 'proposed-brand');

        $changeRequest = $this->entityManager->find(PuzzleChangeRequest::class, PuzzleReportFixture::CHANGE_REQUEST_WITH_IMAGE);
        $brand = $this->entityManager->find(Manufacturer::class, $brandId);
        self::assertNotNull($changeRequest);
        self::assertNotNull($brand);
        $changeRequest->proposedManufacturerMergedInto($brand);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->assertRefused($brandId);
    }

    public function testABrandMergedSlugsRedirectToIsRefused(): void
    {
        $brandId = $this->createEmptyBrand('Merge Survivor', 'merge-survivor');

        $brand = $this->entityManager->find(Manufacturer::class, $brandId);
        self::assertNotNull($brand);
        $this->entityManager->persist(new ManufacturerSlugRedirect('merged-away', $brand, new DateTimeImmutable()));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->assertRefused($brandId);
    }

    private function assertRefused(string $brandId): void
    {
        try {
            $this->delete($brandId);
            self::fail('Expected the deletion to be refused');
        } catch (ManufacturerInUse) {
        }

        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->find(Manufacturer::class, $brandId));
        self::assertSame([], $this->decisions());
    }

    private function createEmptyBrand(string $name, string $slug): string
    {
        $id = Uuid::uuid7();

        $this->entityManager->persist(new Manufacturer(
            $id,
            $name,
            false,
            null,
            new DateTimeImmutable('2026-09-25 10:00:00'),
            slug: $slug,
        ));
        $this->entityManager->flush();
        $this->entityManager->clear();

        return $id->toString();
    }

    private function delete(string $brandId, null|string $note = null): void
    {
        $this->messageBus->dispatch(new DeleteManufacturer(
            manufacturerId: $brandId,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            decisionSource: MergeDecisionSource::InternalApi,
            decisionNote: $note,
        ));

        $this->entityManager->clear();
    }

    /**
     * @return list<PuzzleModerationDecision>
     */
    private function decisions(): array
    {
        return $this->entityManager->getRepository(PuzzleModerationDecision::class)
            ->findBy(['action' => PuzzleModerationAction::BrandDeleted]);
    }
}
