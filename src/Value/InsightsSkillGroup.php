<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The groups of the skill map, in page order. A segment's key: brand = manufacturer id, pieces = the piece-count
 * class, difficulty = the tier (0 = not rated yet), situation = InsightsSituation, personality =
 * InsightsPuzzlePersonality, image traits = InsightsImageTrait.
 */
enum InsightsSkillGroup: string
{
    case Brand = 'brand';
    case Pieces = 'pieces';
    case Difficulty = 'difficulty';
    case Situation = 'situation';
    case Personality = 'personality';
    case ImageTraits = 'image_traits';
}
