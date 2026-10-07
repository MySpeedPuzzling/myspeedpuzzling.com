<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * Something the organiser should know about an applied group of participants sheet changes - never a refusal:
 * `team_result_line_up_changed`, `same_name_as_existing`, `team_name_shared`, `team_size_off`, `waitlisted_member`.
 * Translated by the controller as `participants_sheet_server.warning.<code>` with `parameters` (a null parameter is a
 * pair/team without a name).
 */
readonly final class SheetWarning
{
    public const string TEAM_RESULT_LINE_UP_CHANGED = 'team_result_line_up_changed';
    public const string SAME_NAME_AS_EXISTING = 'same_name_as_existing';
    public const string TEAM_NAME_SHARED = 'team_name_shared';
    public const string TEAM_SIZE_OFF = 'team_size_off';
    public const string WAITLISTED_MEMBER = 'waitlisted_member';

    /**
     * @param array<string, null|string|int> $parameters
     */
    public function __construct(
        public string $code,
        public null|string $participantId = null,
        public null|string $teamId = null,
        public null|string $roundId = null,
        public array $parameters = [],
    ) {
    }

    /**
     * @return array{code: string, participantId: null|string, teamId: null|string, roundId: null|string, parameters: array<string, null|string|int>}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'participantId' => $this->participantId,
            'teamId' => $this->teamId,
            'roundId' => $this->roundId,
            'parameters' => $this->parameters,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $string = static fn (mixed $value): null|string => is_string($value) ? $value : null;

        return new self(
            code: $string($data['code'] ?? null) ?? '',
            participantId: $string($data['participantId'] ?? null),
            teamId: $string($data['teamId'] ?? null),
            roundId: $string($data['roundId'] ?? null),
            parameters: SheetChangeOutcome::parameters($data['parameters'] ?? []),
        );
    }
}
