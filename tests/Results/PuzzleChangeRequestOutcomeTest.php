<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Results;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\PuzzleChangeRequestOutcome;
use SpeedPuzzling\Web\Results\PuzzleChangeRequestOverview;
use SpeedPuzzling\Web\Value\ProposedChangeResult;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\PuzzleReportStatus;

final class PuzzleChangeRequestOutcomeTest extends TestCase
{
    private const string BRAND = '86c57774-e3e4-422e-98fa-152a8ac1141a';

    public function testAProposalLeftOutWhileTheReviewerUploadedAnotherImage(): void
    {
        // Production, 2026-10: 1000 -> 500 pieces proposed, declined - the moderator uploaded a new box photo instead
        $outcome = PuzzleChangeRequestOutcome::of($this->request(proposedPiecesCount: 500), [
            'image' => 'upload',
            'before' => $this->snapshot(image: 'old.jpg'),
            'after' => $this->snapshot(image: 'bluebird-camping-coast-1000.webp'),
        ]);

        self::assertTrue($outcome->recorded);
        self::assertCount(1, $outcome->fields);
        self::assertSame('Pieces', $outcome->fields[0]->label);
        self::assertSame(ProposedChangeResult::NotApplied, $outcome->fields[0]->result);
        self::assertSame(0, $outcome->appliedCount());
        self::assertSame(1, $outcome->notAppliedCount());

        self::assertCount(1, $outcome->reviewerChanges);
        self::assertSame('Image', $outcome->reviewerChanges[0]->label);
        self::assertTrue($outcome->reviewerChanges[0]->image);
        self::assertSame('bluebird-camping-coast-1000.webp', $outcome->reviewerChanges[0]->after);
    }

    public function testAppliedSavedDifferentlyAndProposedImageReplacedByAnUpload(): void
    {
        $outcome = PuzzleChangeRequestOutcome::of(
            $this->request(proposedPiecesCount: 500, proposedName: 'Camping', proposedImage: 'proposal-1.jpg'),
            [
                'image' => 'upload',
                'before' => $this->snapshot(image: 'old.jpg'),
                'after' => $this->snapshot(name: 'Camping', piecesCount: 750, image: 'new.webp'),
            ],
        );

        self::assertSame(ProposedChangeResult::Applied, $outcome->names[0]->result);

        [$pieces, $image] = $outcome->fields;
        self::assertSame(ProposedChangeResult::Altered, $pieces->result);
        self::assertSame('750', $pieces->saved);
        self::assertSame(ProposedChangeResult::Altered, $image->result);
        self::assertSame('new.webp', $image->saved);

        // Every change touched a proposed field
        self::assertSame([], $outcome->reviewerChanges);
    }

    public function testAProposedImageAppliedShowsWhereItIsNow(): void
    {
        $outcome = PuzzleChangeRequestOutcome::of($this->request(proposedImage: 'proposal-1.jpg'), [
            'image' => 'proposed',
            'before' => $this->snapshot(image: 'old.jpg'),
            'after' => $this->snapshot(image: 'camping-coast-1000.jpg'),
        ]);

        self::assertSame(ProposedChangeResult::Applied, $outcome->fields[0]->result);
        self::assertSame('camping-coast-1000.jpg', $outcome->fields[0]->proposed);
    }

    public function testOlderInternalApiApprovalsKnowTheFieldsAppliedAsProposed(): void
    {
        $outcome = PuzzleChangeRequestOutcome::of(
            $this->request(proposedPiecesCount: 500, proposedName: 'Camping'),
            ['selectedFields' => ['name']],
        );

        self::assertTrue($outcome->recorded);
        self::assertSame(ProposedChangeResult::Applied, $outcome->names[0]->result);
        self::assertSame(ProposedChangeResult::NotApplied, $outcome->fields[0]->result);
    }

    public function testAnApprovalWithoutDetailsHasNoResults(): void
    {
        $outcome = PuzzleChangeRequestOutcome::of($this->request(proposedPiecesCount: 500), null);

        self::assertFalse($outcome->recorded);
        self::assertNull($outcome->fields[0]->result);
    }

    public function testARejectionAppliedNothing(): void
    {
        $outcome = PuzzleChangeRequestOutcome::of(
            $this->request(proposedPiecesCount: 500, proposedName: 'Camping', status: PuzzleReportStatus::Rejected),
            null,
        );

        self::assertSame(2, $outcome->notAppliedCount());
        self::assertSame(2, $outcome->proposedCount());
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(string $name = 'Camping Coast', int $piecesCount = 1000, null|string $image = null): array
    {
        return [
            'name' => $name,
            'nameLanguage' => null,
            'alternativeNames' => [],
            'manufacturerId' => self::BRAND,
            'manufacturerName' => 'Bluebird',
            'piecesCount' => $piecesCount,
            'ean' => '3663384910128',
            'identificationNumber' => null,
            'image' => $image,
        ];
    }

    private function request(
        null|int $proposedPiecesCount = null,
        null|string $proposedName = null,
        null|string $proposedImage = null,
        PuzzleReportStatus $status = PuzzleReportStatus::Approved,
    ): PuzzleChangeRequestOverview {
        return new PuzzleChangeRequestOverview(
            id: '019f5abc-4c85-70e1-8875-77c543628476',
            status: $status,
            submittedAt: new DateTimeImmutable('2026-10-05 10:00:00'),
            reviewedAt: new DateTimeImmutable('2026-10-06 10:00:00'),
            rejectionReason: null,
            puzzleId: '019e22c3-7344-732e-bc56-bdab21cc544b',
            puzzleName: 'Camping Coast',
            puzzlePiecesCount: 1000,
            puzzleImage: null,
            puzzleImageRatio: null,
            puzzleManufacturerName: 'Bluebird',
            puzzleAlternativeNames: new PuzzleNames(),
            puzzleNameLanguage: null,
            puzzleRecordVersion: 'v',
            puzzleManufacturerId: self::BRAND,
            puzzleEan: '3663384910128',
            puzzleIdentificationNumber: null,
            reporterId: '019e0000-0000-0000-0000-000000000001',
            reporterName: 'Reporter',
            reporterCode: 'reporter',
            reviewerId: null,
            reviewerName: null,
            // A proposal repeats the fields it does not change
            proposedName: $proposedName ?? 'Camping Coast',
            proposedManufacturerId: self::BRAND,
            proposedManufacturerName: 'Bluebird',
            proposedPiecesCount: $proposedPiecesCount ?? 1000,
            proposedEan: '3663384910128',
            proposedIdentificationNumber: null,
            proposedImage: $proposedImage,
            proposedAlternativeNames: null,
            proposedNameLanguage: null,
            originalName: 'Camping Coast',
            originalManufacturerId: self::BRAND,
            originalManufacturerName: 'Bluebird',
            originalPiecesCount: 1000,
            originalEan: '3663384910128',
            originalIdentificationNumber: null,
            originalImage: 'old.jpg',
            originalAlternativeNames: new PuzzleNames(),
            originalNameLanguage: null,
        );
    }
}
