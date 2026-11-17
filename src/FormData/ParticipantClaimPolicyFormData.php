<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Value\ParticipantClaimPolicy;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The claim policy radios of the toolkit hub's "Participants & profiles" card (docs/features/competitions-management/
 * participant-linking.md §7.5): automatic (null stored), open or approval.
 */
final class ParticipantClaimPolicyFormData
{
    public const string AUTOMATIC = 'automatic';

    #[Assert\NotBlank]
    #[Assert\Choice(choices: [self::AUTOMATIC, 'open', 'approval'])]
    public null|string $policy = self::AUTOMATIC;

    public static function fromStored(null|ParticipantClaimPolicy $stored): self
    {
        $data = new self();
        $data->policy = $stored !== null ? $stored->value : self::AUTOMATIC;

        return $data;
    }

    public function toPolicy(): null|ParticipantClaimPolicy
    {
        return $this->policy === self::AUTOMATIC || $this->policy === null ? null : ParticipantClaimPolicy::tryFrom($this->policy);
    }
}
