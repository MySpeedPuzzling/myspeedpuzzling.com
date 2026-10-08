<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\SheetChangeStatus;

/**
 * The server's answer to one change of a participants sheet group (ApplyParticipantSheetChanges). `reason` is a code -
 * `participants_sheet_server.reason.<reason>`, translated by the controller with `parameters` (%name%, %round%, … - a
 * null parameter is a pair/team without a name) - and `current` the value the server holds for the changed cell: the
 * `to` of an applied or unchanged change, what is there instead for a conflict, refusal or skipped change (`field`
 * string|null, `player` id|null, `place` out/in/team:<id>, `renameTeam` name|null, `teamSize` int|null; null for the
 * other ops). `cause` tells apart what refused a change when one code has several causes - it picks the organiser's text
 * (`participants_sheet_server.reason.<reason>_<cause>`), the code stays: `own_time` (has_result_in_round/_event - the
 * linked player's own time, which nobody but the player can take away), `emptied` / `waitlisted_only` (team_has_result -
 * a group would leave the pair/team with nobody / with people on the waitlist only); null = the code's own text.
 */
readonly final class SheetChangeOutcome
{
    /**
     * @param array<string, null|string|int> $parameters
     */
    public function __construct(
        public int $index,
        public SheetChangeStatus $status,
        public null|string $reason = null,
        public null|string|int $current = null,
        public array $parameters = [],
        public null|string $cause = null,
    ) {
    }

    /**
     * What a change set receipt keeps (ParticipantSheetChangeReceipt::$outcomes) - codes and parameters, never
     * translated texts, so a replay is answered in the language it asks in.
     *
     * @return array{index: int, status: string, reason: null|string, current: null|string|int, parameters: array<string, null|string|int>, cause: null|string}
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'current' => $this->current,
            'parameters' => $this->parameters,
            'cause' => $this->cause,
        ];
    }

    /**
     * The translation key of the organiser's text - null for a change that went through.
     */
    public function messageKey(): null|string
    {
        if ($this->reason === null) {
            return null;
        }

        return 'participants_sheet_server.reason.' . $this->reason . ($this->cause !== null ? '_' . $this->cause : '');
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $index = $data['index'] ?? 0;
        $status = $data['status'] ?? null;
        $reason = $data['reason'] ?? null;
        $current = $data['current'] ?? null;
        $cause = $data['cause'] ?? null;

        return new self(
            index: is_int($index) ? $index : 0,
            status: is_string($status) ? (SheetChangeStatus::tryFrom($status) ?? SheetChangeStatus::Refused) : SheetChangeStatus::Refused,
            reason: is_string($reason) ? $reason : null,
            current: is_string($current) || is_int($current) ? $current : null,
            parameters: self::parameters($data['parameters'] ?? []),
            cause: is_string($cause) ? $cause : null,
        );
    }

    /**
     * @return array<string, null|string|int>
     */
    public static function parameters(mixed $parameters): array
    {
        $clean = [];

        if (!is_array($parameters)) {
            return $clean;
        }

        foreach ($parameters as $name => $value) {
            if (is_string($name) && ($value === null || is_string($value) || is_int($value))) {
                $clean[$name] = $value;
            }
        }

        return $clean;
    }
}
