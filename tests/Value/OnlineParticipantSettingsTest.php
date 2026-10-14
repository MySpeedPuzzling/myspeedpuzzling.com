<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use SpeedPuzzling\Web\Entity\OnlineRound;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\OnlineRoundsFixture;
use SpeedPuzzling\Web\Value\OnlineDetectionMode;
use SpeedPuzzling\Web\Value\OnlineMissingPieceRule;
use SpeedPuzzling\Web\Value\OnlineParticipantSettings;
use SpeedPuzzling\Web\Value\OnlineUnfinishedMeasure;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The settings a participant's phone uses and their hash (stream SP, README §5.3): the hash changes with every one of
 * them - finish detection, live video, see each other, the studio, the time's-up measure - and with nothing else (the
 * recording, the missing-piece rule, the studio's YouTube link: no phone uses them, so changing them sends no phone to
 * fetch its state). The same settings always hash alike.
 */
final class OnlineParticipantSettingsTest extends KernelTestCase
{
    public function testTheSettingsAsThePhoneGetsThem(): void
    {
        $settings = OnlineParticipantSettings::of($this->onlineRound());

        self::assertSame([
            'detection' => 'off',
            'video' => false,
            'seeEachOther' => false,
            'studio' => false,
            'unfinishedMeasure' => 'left',
        ], $settings->toArray());
        self::assertSame(substr(sha1((string) json_encode($settings->toArray())), 0, 12), $settings->hash());
        self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $settings->hash());
    }

    public function testTheHashChangesWithEverySettingThePhoneUses(): void
    {
        $onlineRound = $this->onlineRound();
        $hashes = [$this->hash($onlineRound)];

        foreach (
            [
            'detection shadow' => ['detectionMode' => OnlineDetectionMode::Shadow],
            'detection on' => ['detectionMode' => OnlineDetectionMode::On],
            'live video' => ['videoEnabled' => true],
            'see each other' => ['participantsSeeEachOther' => true],
            'the studio' => ['studioEnabled' => true],
            'pieces placed' => ['unfinishedMeasure' => OnlineUnfinishedMeasure::PiecesPlaced],
            ] as $change => $settings
        ) {
            self::change($onlineRound, $settings);
            $hash = $this->hash($onlineRound);
            self::assertNotContains($hash, $hashes, $change);
            $hashes[] = $hash;
        }

        // Back to where it began: the hash it began with
        self::change($onlineRound, [
            'detectionMode' => OnlineDetectionMode::Off,
            'videoEnabled' => false,
            'participantsSeeEachOther' => false,
            'studioEnabled' => false,
            'unfinishedMeasure' => OnlineUnfinishedMeasure::PiecesLeft,
        ]);
        self::assertSame($hashes[0], $this->hash($onlineRound));
    }

    public function testWhatNoPhoneUsesNeverChangesTheHash(): void
    {
        $onlineRound = $this->onlineRound();
        $before = $this->hash($onlineRound);

        self::change($onlineRound, ['recordingEnabled' => true, 'missingPieceRule' => OnlineMissingPieceRule::PenaltyFiveSeconds]);
        $onlineRound->changeYoutubeUrl('https://www.youtube.com/watch?v=abc');

        self::assertSame($before, $this->hash($onlineRound));
    }

    private function onlineRound(): OnlineRound
    {
        self::bootKernel();

        return self::getContainer()->get(OnlineRoundRepository::class)->get(OnlineRoundsFixture::ONLINE_ROUND_SOLO);
    }

    private function hash(OnlineRound $onlineRound): string
    {
        return OnlineParticipantSettings::of($onlineRound)->hash();
    }

    /**
     * @param array{detectionMode?: OnlineDetectionMode, videoEnabled?: bool, participantsSeeEachOther?: bool, studioEnabled?: bool, recordingEnabled?: bool, unfinishedMeasure?: OnlineUnfinishedMeasure, missingPieceRule?: OnlineMissingPieceRule} $settings
     */
    private static function change(OnlineRound $onlineRound, array $settings): void
    {
        $onlineRound->changeSettings(
            detectionMode: $settings['detectionMode'] ?? $onlineRound->detectionMode,
            videoEnabled: $settings['videoEnabled'] ?? $onlineRound->videoEnabled,
            participantsSeeEachOther: $settings['participantsSeeEachOther'] ?? $onlineRound->participantsSeeEachOther,
            studioEnabled: $settings['studioEnabled'] ?? $onlineRound->studioEnabled,
            recordingEnabled: $settings['recordingEnabled'] ?? $onlineRound->recordingEnabled,
            unfinishedMeasure: $settings['unfinishedMeasure'] ?? $onlineRound->unfinishedMeasure,
            missingPieceRule: $settings['missingPieceRule'] ?? $onlineRound->missingPieceRule,
        );
    }
}
