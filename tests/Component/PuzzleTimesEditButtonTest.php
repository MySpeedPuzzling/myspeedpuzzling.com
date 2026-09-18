<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Only the player who tracked a time may edit it (EditTimeController answers
 * everybody else with 403), but every member of a duo/team sees that time among
 * their own attempts. Production logged 75 such 403s in 30 days - all of them
 * group members clicking an edit button that was never theirs, several times
 * in a row because the modal just did not open.
 */
final class PuzzleTimesEditButtonTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    // TIME_12: duo on PUZZLE_1000_01, tracked by PLAYER_REGULAR with PLAYER_PRIVATE as the partner
    private const string EDIT_TIME_12 = '/en/edit-time/' . PuzzleSolvingTimeFixture::TIME_12;

    public function testPlayerWhoTrackedTheGroupTimeIsOfferedTheEditButton(): void
    {
        $html = $this->renderDuoTimesAs(PlayerFixture::PLAYER_REGULAR);

        self::assertStringContainsString('My time:', $html);
        self::assertStringContainsString(self::EDIT_TIME_12, $html);
    }

    public function testGroupMemberWhoDidNotTrackTheTimeIsNotOfferedTheEditButton(): void
    {
        $html = $this->renderDuoTimesAs(PlayerFixture::PLAYER_PRIVATE);

        // The time is still theirs to see - just not to edit
        self::assertStringContainsString('My time:', $html);
        self::assertStringNotContainsString(self::EDIT_TIME_12, $html);
    }

    private function renderDuoTimesAs(string $playerId): string
    {
        $client = self::createClient();
        TestingLogin::asPlayer($client, $playerId);

        $component = $this->createLiveComponent('PuzzleTimes', [
            'puzzleId' => PuzzleFixture::PUZZLE_1000_01,
            'piecesCount' => 1000,
            'category' => 'duo',
        ], $client);
        $component->setRouteLocale('en');

        return $component->render()->toString();
    }
}
