<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The sections of the Insights page - anchors for question chips and findings, and what is free vs members only
 * (README §3: free = the profile card, findings, close calls, form, the median histogram, results per weekday).
 */
enum InsightsSection: string
{
    case Findings = 'findings';
    case Profile = 'profile';
    case Form = 'form';
    case SkillMap = 'skill_map';
    case ExpectedVsActual = 'expected';
    case Histogram = 'histogram';
    case Outliers = 'outliers';
    case CloseCalls = 'close_calls';
    case LearningCurve = 'learning';
    case Habits = 'habits';
    case Consistency = 'consistency';
    case PredictionAccuracy = 'accuracy';

    public function isFree(): bool
    {
        return match ($this) {
            self::Findings, self::Profile, self::Form, self::Histogram, self::CloseCalls, self::Habits => true,
            default => false,
        };
    }
}
