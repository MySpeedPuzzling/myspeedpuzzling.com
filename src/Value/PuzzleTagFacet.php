<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The facets of the image taxonomy (docs/features/insights/image-taxonomy.md §10, Appendix A): every tag key is
 * `<facet>.<key>` (subthemes `theme.<parent>.<child>`), and every key belongs to exactly one facet.
 */
enum PuzzleTagFacet: string
{
    case Theme = 'theme';
    case Subject = 'subject';
    case Style = 'style';
    case Scene = 'scene';
    case Season = 'season';
    case Holiday = 'holiday';
    case Time = 'time';
    case Weather = 'weather';
    case Mood = 'mood';
    case People = 'people';
    case Audience = 'audience';
    case Special = 'special';
    case Licence = 'licence';
    case Trait = 'trait';
    case Skill = 'skill';
    case Image = 'image';
}
