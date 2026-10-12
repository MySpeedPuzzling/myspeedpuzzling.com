<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The Help sheet's feedback box (docs/features/navigation README §4.5, HELP-01b). `url` is the page the sheet was opened
 * on as the sheet knew it - untrusted: FeedbackController reads it through HelpPageContextFactory again.
 */
final class FeedbackFormData
{
    public const int MESSAGE_MAX_LENGTH = 5000;

    public string $url = '';

    #[Assert\NotBlank(message: 'feedback.message_required', normalizer: 'trim')]
    #[Assert\Length(max: self::MESSAGE_MAX_LENGTH, maxMessage: 'feedback.message_too_long')]
    public string $message = '';
}
