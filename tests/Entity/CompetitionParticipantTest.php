<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Entity;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Value\ParticipantSource;

final class CompetitionParticipantTest extends TestCase
{
    private CompetitionParticipant $participant;

    protected function setUp(): void
    {
        $competition = $this->createMock(Competition::class);

        $this->participant = new CompetitionParticipant(
            id: Uuid::uuid7(),
            name: 'Jan Mikeš',
            country: 'cz',
            competition: $competition,
            source: ParticipantSource::Imported,
        );
    }

    public function testSoftDeleteSetsDeletedAt(): void
    {
        $now = new DateTimeImmutable();
        $this->participant->softDelete($now);

        self::assertTrue($this->participant->isDeleted());
        self::assertSame($now, $this->participant->deletedAt);
    }

    public function testRestoreClearsDeletedAt(): void
    {
        $this->participant->softDelete(new DateTimeImmutable());
        self::assertTrue($this->participant->isDeleted());

        $this->participant->restore();

        self::assertFalse($this->participant->isDeleted());
        self::assertNull($this->participant->deletedAt);
    }

    public function testUpdateNameChangesName(): void
    {
        self::assertSame('Jan Mikeš', $this->participant->name);

        $this->participant->updateName('Honza M.');

        self::assertSame('Honza M.', $this->participant->name);
    }

    public function testUpdateCountryChangesCountry(): void
    {
        self::assertSame('cz', $this->participant->country);

        $this->participant->updateCountry('de');
        self::assertSame('de', $this->participant->country);

        $this->participant->updateCountry(null);
        self::assertNull($this->participant->country);
    }

    public function testUpdateExternalId(): void
    {
        self::assertNull($this->participant->externalId);

        $this->participant->updateExternalId('EXT-001');
        self::assertSame('EXT-001', $this->participant->externalId);

        $this->participant->updateExternalId(null);
        self::assertNull($this->participant->externalId);
    }

    public function testIsDeletedReturnsFalseByDefault(): void
    {
        self::assertFalse($this->participant->isDeleted());
    }

    public function testSourceIsSetInConstructor(): void
    {
        self::assertSame(ParticipantSource::Imported, $this->participant->source);
    }

    public function testDefaultSourceIsImported(): void
    {
        $competition = $this->createMock(Competition::class);

        $participant = new CompetitionParticipant(
            id: Uuid::uuid7(),
            name: 'Test',
            country: null,
            competition: $competition,
        );

        self::assertSame(ParticipantSource::Imported, $participant->source);
    }
}
