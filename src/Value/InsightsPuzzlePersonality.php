<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * "Puzzle personality" from the existing derived metrics of puzzle_difficulty - the "high" (or for the equaliser the
 * "low") levels the puzzle page already labels (templates/puzzle_intelligence/_macros.html.twig personality_metric).
 */
enum InsightsPuzzlePersonality: string
{
    /** Big gap between fast and slow solvers: skill sensitivity ≥ 1.8 */
    case RewardsSkill = 'rewards_skill';
    /** Most people finish close together: skill sensitivity < 1.3 */
    case Equaliser = 'equaliser';
    /** Much harder without the box: box dependence ≥ 1.4 */
    case BoxHelps = 'box_helps';
    /** Much easier the second time: memorability ≥ 1.4 */
    case Memorable = 'memorable';

    public const float SKILL_SENSITIVITY_HIGH = 1.8;
    public const float SKILL_SENSITIVITY_LOW = 1.3;
    public const float BOX_DEPENDENCE_HIGH = 1.4;
    public const float MEMORABILITY_HIGH = 1.4;

    /**
     * @return list<self>
     */
    public static function of(null|float $skillSensitivity, null|float $boxDependence, null|float $memorability): array
    {
        $personalities = [];

        if ($skillSensitivity !== null && $skillSensitivity >= self::SKILL_SENSITIVITY_HIGH) {
            $personalities[] = self::RewardsSkill;
        }

        if ($skillSensitivity !== null && $skillSensitivity < self::SKILL_SENSITIVITY_LOW) {
            $personalities[] = self::Equaliser;
        }

        if ($boxDependence !== null && $boxDependence >= self::BOX_DEPENDENCE_HIGH) {
            $personalities[] = self::BoxHelps;
        }

        if ($memorability !== null && $memorability >= self::MEMORABILITY_HIGH) {
            $personalities[] = self::Memorable;
        }

        return $personalities;
    }
}
