<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\ParticipantsSheet;

/**
 * The MySpeedPuzzling profile a participant is linked to, as the viewer of the spreadsheet may see it. The participant
 * row itself (the organiser's name and country) is never hidden - organiser tooling. The profile's identity (name,
 * #code, country, avatar, link) is withheld when the viewer blocks the player (HiddenPlayers - the viewer's own blocks
 * only) or the profile is private to the viewer (PrivateProfileAccess, the allow list included; never to themselves):
 * then only the id travels and the sheet says "Linked to a MySpeedPuzzling profile" - the published round page's rule.
 */
readonly final class ParticipantsSheetPlayer implements \JsonSerializable
{
    private function __construct(
        public string $id,
        public bool $visible,
        public null|string $name,
        public null|string $code,
        public null|string $country,
        public null|string $avatar,
        public null|string $profileUrl,
    ) {
    }

    public static function visible(string $id, null|string $name, string $code, null|string $country, null|string $avatar, string $profileUrl): self
    {
        return new self($id, true, $name, $code, $country, $avatar, $profileUrl);
    }

    public static function withheld(string $id): self
    {
        return new self($id, false, null, null, null, null, null);
    }

    /**
     * @return array{id: string, visible: bool, name: null|string, code: null|string, country: null|string, avatar: null|string, profileUrl: null|string}
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'visible' => $this->visible,
            'name' => $this->name,
            'code' => $this->code,
            'country' => $this->country,
            'avatar' => $this->avatar,
            'profileUrl' => $this->profileUrl,
        ];
    }
}
