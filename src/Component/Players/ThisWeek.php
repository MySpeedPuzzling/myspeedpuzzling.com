<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component\Players;

use SpeedPuzzling\Web\Query\GetPlayersOnARoll;
use SpeedPuzzling\Web\Results\PlayerOnARoll;
use SpeedPuzzling\Web\Value\CommunityScope;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * This week (docs/features/players-page/README.md, stream S3): On a roll - people with the most solves in the last
 * 7 days, with chips for their moments. Renders nothing when nobody in the scope solved anything this week.
 */
#[AsTwigComponent]
final class ThisWeek
{
    // Two rows of three on desktop; phones show PEOPLE_ON_PHONES and reveal the rest with "Show more"
    public const int PEOPLE = 6;
    public const int PEOPLE_ON_PHONES = 3;

    public CommunityScope $scope;

    public function __construct(
        readonly private GetPlayersOnARoll $getPlayersOnARoll,
    ) {
        $this->scope = CommunityScope::world();
    }

    /**
     * @return list<PlayerOnARoll>
     */
    public function getPeople(): array
    {
        return $this->getPlayersOnARoll->forScope($this->scope, self::PEOPLE);
    }
}
