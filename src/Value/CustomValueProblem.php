<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Why a value typed into a player's own field was not taken (CustomValues::normalise()).
 */
enum CustomValueProblem: string
{
    case NotValid = 'not_valid';
    case TooLong = 'too_long';
    case NotANumber = 'not_a_number';
    case NotADate = 'not_a_date';
    case NotYesNo = 'not_yes_no';
    case UnknownChoice = 'unknown_choice';
    case TooManyTags = 'too_many_tags';
    case TagTooLong = 'tag_too_long';
    case UnknownField = 'unknown_field';

    public function messageKey(): string
    {
        return 'my_fields.errors.' . $this->value;
    }
}
