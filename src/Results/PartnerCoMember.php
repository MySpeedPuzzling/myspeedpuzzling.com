<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A registered player named in a partner finder group listing, as the viewer may see them: a private player the viewer
 * may not see keeps only the flag (no name, no avatar, no link).
 */
readonly final class PartnerCoMember
{
    public function __construct(
        public string $playerId,
        public null|string $name,
        public string $code,
        public null|string $countryCode,
        public null|string $avatar,
        public bool $masked,
    ) {
    }

    /**
     * @param array{id: string, name: null|string, code: string, country: null|string, avatar: null|string, masked: null|bool} $row
     */
    public static function fromJson(array $row): self
    {
        $masked = $row['masked'] === true;

        return new self(
            playerId: $row['id'],
            name: $masked ? null : $row['name'],
            code: $row['code'],
            countryCode: $row['country'],
            avatar: $masked ? null : $row['avatar'],
            masked: $masked,
        );
    }
}
