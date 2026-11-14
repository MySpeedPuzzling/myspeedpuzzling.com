<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\CustomFields;

use SpeedPuzzling\Web\Entity\CustomField;
use SpeedPuzzling\Web\Exceptions\CustomFieldLimitReached;
use SpeedPuzzling\Web\Exceptions\CustomFieldNameTaken;
use SpeedPuzzling\Web\Exceptions\InvalidCustomFieldDefinition;
use SpeedPuzzling\Web\Value\CustomFieldDefinition;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * What a refused add, edit or restore of a custom field tells the player - anything else is not a refusal and goes on
 * as the failure it is.
 */
readonly final class CustomFieldRefusalMessage
{
    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @throws HandlerFailedException
     */
    public function of(HandlerFailedException $exception): string
    {
        $refusal = $exception->getPrevious();

        if ($refusal instanceof CustomFieldLimitReached) {
            return $this->translator->trans('my_fields.flash.limit_reached', ['%limit%' => CustomField::MAX_ACTIVE_PER_PLAYER]);
        }

        if ($refusal instanceof CustomFieldNameTaken) {
            return $this->translator->trans('my_fields.flash.name_taken');
        }

        if ($refusal instanceof InvalidCustomFieldDefinition) {
            return $this->translator->trans($refusal->messageKey(), [
                '%limit%' => match ($refusal->reason) {
                    InvalidCustomFieldDefinition::TOO_MANY_CHOICES => CustomFieldDefinition::MAX_CHOICES,
                    InvalidCustomFieldDefinition::CHOICE_TOO_LONG => CustomFieldDefinition::CHOICE_MAX_LENGTH,
                    default => CustomFieldDefinition::NAME_MAX_LENGTH,
                },
            ]);
        }

        throw $exception;
    }
}
