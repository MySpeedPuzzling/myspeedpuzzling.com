<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Entity\CompetitionTeam;
use SpeedPuzzling\Web\Value\CompetitionTeamNames;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[Callback('validate')]
final class AddCompetitionTeamsFormData
{
    // One team name per line
    public null|string $teamNames = null;

    public function names(): CompetitionTeamNames
    {
        return CompetitionTeamNames::fromText($this->teamNames);
    }

    public function validate(ExecutionContextInterface $context): void
    {
        $names = $this->names();

        foreach ($names->tooLong() as $name) {
            $context->buildViolation('competition_teams.name_too_long')
                ->setParameter('%name%', mb_substr($name, 0, 40) . '…')
                ->setParameter('%length%', (string) mb_strlen($name))
                ->setParameter('%max%', (string) CompetitionTeam::NAME_MAX_LENGTH)
                ->atPath('teamNames')
                ->addViolation();
        }

        if ($names->tooMany()) {
            $context->buildViolation('competition_teams.too_many')
                ->setParameter('%max%', (string) CompetitionTeamNames::MAX_TEAMS_AT_ONCE)
                ->atPath('teamNames')
                ->addViolation();
        }
    }
}
