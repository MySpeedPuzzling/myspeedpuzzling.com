<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What the reviewer of an online round said about one finish moment's photo (docs/features/online-competitions/
 * README.md §5.9): `gap` - the photo at that raise of the hands (a finish candidate) or that Finished tap shows an
 * unfinished puzzle; the reviewer pressed G and went on to the next moment. A moment without a verdict is simply not
 * marked. A raise marked as a gap is never a finish's raise - never suggested, never the detected one of the accuracy
 * report (a premature raise or tap, against the detector's own false detection); a confirmation or an official time
 * never reads it.
 */
enum OnlineMomentVerdict: string
{
    case Gap = 'gap';
}
