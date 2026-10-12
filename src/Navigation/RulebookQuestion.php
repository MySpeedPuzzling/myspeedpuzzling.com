<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * The placement rulebook (navigation README §7): asked in order, the first yes decides where a destination lives.
 */
enum RulebookQuestion: string
{
    case Q1 = 'Q1';
    case Q2 = 'Q2';
    case Q3 = 'Q3';
    case Q4 = 'Q4';
    case Q5 = 'Q5';
    case Q6 = 'Q6';
    case Q7 = 'Q7';
    case Q8 = 'Q8';
    case Q9 = 'Q9';
    case Q10 = 'Q10';

    public function summary(): string
    {
        return match ($this) {
            self::Q1 => 'Action on a thing that has a page: a button on that page',
            self::Q2 => 'Page of one object: no nav entry, reached from lists and search',
            self::Q3 => 'Only for a role: Menu › Your roles',
            self::Q4 => 'Creates or records something: the Add page',
            self::Q5 => 'Needed on every page: the top bar',
            self::Q6 => 'Configures your account: Settings and search',
            self::Q7 => 'Explains, helps, collects feedback, about MSP: Help & feedback',
            self::Q8 => 'About one player: player tab or Menu › Your puzzling / Your puzzles / Account',
            self::Q9 => 'Shared catalogue or community content: Puzzles or Community',
            self::Q10 => 'Claimed top task: a place',
        };
    }
}
