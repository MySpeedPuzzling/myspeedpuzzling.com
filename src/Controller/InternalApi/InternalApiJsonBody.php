<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\InternalApi;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class InternalApiJsonBody
{
    /**
     * Decodes the JSON request body for internal API endpoints.
     * Empty body is treated as no fields (`{}`). Invalid JSON raises 400.
     *
     * @return array<string, mixed>
     */
    public static function parse(Request $request): array
    {
        $content = $request->getContent();

        if ($content === '') {
            return [];
        }

        try {
            $data = json_decode($content, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new BadRequestHttpException('Invalid JSON payload.', $e);
        }

        if (is_array($data) === false) {
            throw new BadRequestHttpException('JSON payload must be an object.');
        }

        /** @var array<string, mixed> $data */
        return $data;
    }
}
