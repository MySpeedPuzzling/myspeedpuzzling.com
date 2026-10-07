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
 * other ops).
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
    ) {
    }

    /**
     * What a change set receipt keeps (ParticipantSheetChangeReceipt::$outcomes) - codes and parameters, never
     * translated texts, so a replay is answered in the language it asks in.
     *
     * @return array{index: int, status: string, reason: null|string, current: null|string|int, parameters: array<string, null|string|int>}
     */
    public function toArray(): array
    {
        return [
            'index' => $this->index,
            'status' => $this->status->value,
            'reason' => $this->reason,
            'current' => $this->current,
            'parameters' => $this->parameters,
        ];
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

        return new self(
            index: is_int($index) ? $index : 0,
            status: is_string($status) ? (SheetChangeStatus::tryFrom($status) ?? SheetChangeStatus::Refused) : SheetChangeStatus::Refused,
            reason: is_string($reason) ? $reason : null,
            current: is_string($current) || is_int($current) ? $current : null,
            parameters: self::parameters($data['parameters'] ?? []),
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
