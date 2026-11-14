<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

/**
 * A field can not be set up like this (CustomFieldDefinition): the reason is the last part of its message key
 * `my_fields.definition_errors.<reason>`.
 */
final class InvalidCustomFieldDefinition extends \Exception
{
    public const string NAME_MISSING = 'name_missing';
    public const string NAME_TOO_LONG = 'name_too_long';
    public const string CHOICES_MISSING = 'choices_missing';
    public const string TOO_MANY_CHOICES = 'too_many_choices';
    public const string CHOICE_TOO_LONG = 'choice_too_long';
    public const string NO_TARGET = 'no_target';
    public const string READY_MADE = 'ready_made';
    public const string INVALID_CHARACTERS = 'invalid_characters';

    public function __construct(
        public readonly string $reason,
    ) {
        parent::__construct('Custom field definition refused: ' . $reason);
    }

    public function messageKey(): string
    {
        return 'my_fields.definition_errors.' . $this->reason;
    }
}
