<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Value;

use SpeedPuzzling\Web\Value\Puzzler;
use PHPUnit\Framework\TestCase;

class PuzzlerTest extends TestCase
{
    public function testCreatePuzzlersFromJson(): void
    {
        $json = '[{"player_id" : "018dd95e-01ba-70d5-bf6e-246a31437f04", "player_name" : "Jenn/PuzzleKnucks", "player_country" : "us"},{"player_id" : "018def45-b4d8-71c0-8cbc-66c6eee129e6", "player_name" : "Turtle", "player_country" : "us"},{"player_id" : null, "player_name" : "Kamila", "player_country" : null},{"player_id" : null, "player_name" : "Jamie", "player_country" : null}]';

        $group = Puzzler::createPuzzlersFromJson($json);

        self::assertCount(4, $group);
    }
}
