<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\InternalApi;

use SpeedPuzzling\Web\Controller\InternalApi\InternalApiInput;
use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Exceptions\CompetitionSeriesNotFound;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * The logo of an organization create / update body (docs/features/internal-api.md "Organizations, series and drafts"),
 * there being no file upload in a JSON API:
 * - `logoFromCompetitionId` / `logoFromSeriesId` - the organization shows the logo that event or series already has.
 *   Its stored path is shared, like an organization made from a series shares the series' logo: logos are never
 *   deleted when replaced, so neither side can lose it;
 * - `logoBase64` - the image itself (a `data:image/...;base64,` prefix is allowed), stored like the web form's upload
 *   and checked by the same rule (an image, JPEG / PNG / GIF / WebP, at most 5 MB).
 */
final readonly class OrganizationLogoInput
{
    public const array FIELDS = ['logoFromCompetitionId', 'logoFromSeriesId', 'logoBase64'];

    private const int MAX_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private CompetitionRepository $competitionRepository,
        private CompetitionSeriesRepository $competitionSeriesRepository,
        private ValidatorInterface $validator,
    ) {
    }

    /**
     * @return array{storedLogo: null|string, uploadedLogo: null|UploadedFile} the uploaded file is a temporary one -
     *     the caller removes it once the message is handled
     */
    public function read(InternalApiInput $input): array
    {
        $sent = array_values(array_filter(self::FIELDS, static fn (string $field): bool => $input->has($field)));

        if (count($sent) > 1) {
            $input->addError($sent[1], sprintf('send only one of %s.', implode(', ', self::FIELDS)));

            return ['storedLogo' => null, 'uploadedLogo' => null];
        }

        $competitionId = $input->id('logoFromCompetitionId');
        if ($competitionId !== null) {
            try {
                $logo = $this->competitionRepository->get($competitionId)->logo;
            } catch (CompetitionNotFound) {
                $input->addError('logoFromCompetitionId', 'no competition has this id.');

                return ['storedLogo' => null, 'uploadedLogo' => null];
            }

            if ($logo === null) {
                $input->addError('logoFromCompetitionId', 'this competition has no logo.');
            }

            return ['storedLogo' => $logo, 'uploadedLogo' => null];
        }

        $seriesId = $input->id('logoFromSeriesId');
        if ($seriesId !== null) {
            try {
                $logo = $this->competitionSeriesRepository->get($seriesId)->logo;
            } catch (CompetitionSeriesNotFound) {
                $input->addError('logoFromSeriesId', 'no series has this id.');

                return ['storedLogo' => null, 'uploadedLogo' => null];
            }

            if ($logo === null) {
                $input->addError('logoFromSeriesId', 'this series has no logo.');
            }

            return ['storedLogo' => $logo, 'uploadedLogo' => null];
        }

        $base64 = $input->string('logoBase64');
        if ($base64 === null) {
            return ['storedLogo' => null, 'uploadedLogo' => null];
        }

        return ['storedLogo' => null, 'uploadedLogo' => $this->decode($input, $base64)];
    }

    private function decode(InternalApiInput $input, string $base64): null|UploadedFile
    {
        $base64 = preg_replace('/^data:image\/[a-z0-9.+-]+;base64,/i', '', $base64) ?? $base64;
        $bytes = base64_decode($base64, true);

        if ($bytes === false || $bytes === '') {
            $input->addError('logoBase64', 'must be a base64-encoded image.');

            return null;
        }

        if (strlen($bytes) > self::MAX_BYTES) {
            $input->addError('logoBase64', 'the image can be at most 5 MB.');

            return null;
        }

        $path = tempnam(sys_get_temp_dir(), 'org-logo-');
        if ($path === false || file_put_contents($path, $bytes) === false) {
            throw new \RuntimeException('Could not write the logo into a temporary file.');
        }

        $file = new UploadedFile($path, 'logo', null, null, true);
        $violations = $this->validator->validate($file, new Image(
            maxSize: '5M',
            mimeTypes: ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        ));

        if (count($violations) > 0) {
            unlink($path);
            $input->addError('logoBase64', (string) $violations->get(0)->getMessage());

            return null;
        }

        return $file;
    }
}
