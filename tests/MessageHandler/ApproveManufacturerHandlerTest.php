<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Manufacturer;
use SpeedPuzzling\Web\Entity\PuzzleModerationDecision;
use SpeedPuzzling\Web\Exceptions\ManufacturerAlreadyApproved;
use SpeedPuzzling\Web\Exceptions\ManufacturerNameTaken;
use SpeedPuzzling\Web\Message\ApproveManufacturer;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Value\MergeDecisionSource;
use SpeedPuzzling\Web\Value\PuzzleModerationAction;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class ApproveManufacturerHandlerTest extends KernelTestCase
{
    private MessageBusInterface $messageBus;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->messageBus = self::getContainer()->get(MessageBusInterface::class);
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testApprovesWithTheBrandsOwnSpellingAndRecordsWhoApproved(): void
    {
        $this->approve(ManufacturerFixture::MANUFACTURER_UNAPPROVED, name: ' Unknown Brand Puzzles ', note: 'Own website');

        $brand = $this->entityManager->find(Manufacturer::class, ManufacturerFixture::MANUFACTURER_UNAPPROVED);
        self::assertTrue($brand?->approved);
        self::assertSame('Unknown Brand Puzzles', $brand->name);

        $decisions = $this->decisions();
        self::assertCount(1, $decisions);
        self::assertSame(MergeDecisionSource::InternalApi, $decisions[0]->source);
        self::assertSame('Own website', $decisions[0]->note);
        self::assertSame('Unknown Brand', $decisions[0]->details['previousName'] ?? null);
    }

    public function testAnApprovedBrandIsRefused(): void
    {
        $this->expectException(ManufacturerAlreadyApproved::class);

        $this->approve(ManufacturerFixture::MANUFACTURER_TREFL);
    }

    public function testADuplicateOfAnApprovedBrandIsRefused(): void
    {
        try {
            $this->approve(ManufacturerFixture::MANUFACTURER_UNAPPROVED, name: 'trefl ');
            self::fail('Expected the approval to be refused');
        } catch (ManufacturerNameTaken) {
        }

        $this->entityManager->clear();
        self::assertFalse($this->entityManager->find(Manufacturer::class, ManufacturerFixture::MANUFACTURER_UNAPPROVED)?->approved);
        self::assertSame([], $this->decisions());
    }

    private function approve(string $manufacturerId, null|string $name = null, null|string $note = null): void
    {
        $this->messageBus->dispatch(new ApproveManufacturer(
            manufacturerId: $manufacturerId,
            reviewerId: PlayerFixture::PLAYER_ADMIN,
            decisionSource: MergeDecisionSource::InternalApi,
            name: $name,
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
            ->findBy(['action' => PuzzleModerationAction::BrandApproved]);
    }
}
