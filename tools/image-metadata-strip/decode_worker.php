<?php

// Long-running decode worker for verify_full.py (runs in the app image: PHP + Imagick).
// Reads one job per line on stdin, answers one JSON line per job on stdout:
//   C|<id>|<before path>|<after path>   compare: decode both the way a browser shows them
//                                       (EXIF orientation applied; HEIF: libheif applies irot/imir)
//                                       -> shown size + SHA-256 of the decoded pixels of each
//   D|<id>|<path>                       decode only -> format + size (rendering check)

foreach ([
    Imagick::RESOURCETYPE_MEMORY => 1536 * 1024 * 1024,
    Imagick::RESOURCETYPE_MAP => 3072 * 1024 * 1024,
    Imagick::RESOURCETYPE_AREA => 128 * 1000 * 1000,
    Imagick::RESOURCETYPE_DISK => 8192 * 1024 * 1024,
] as $type => $limit) {
    try {
        Imagick::setResourceLimit($type, $limit);
    } catch (Throwable) {
        // policy.xml caps it - keep going with its value
    }
}

function shown(string $path): array
{
    $image = new Imagick($path);

    try {
        $format = $image->getImageFormat();
        $orientation = $image->getImageOrientation();

        if (!in_array($format, ['HEIC', 'HEIF', 'AVIF'], true)) {
            $image->autoOrient();
        }

        return [
            'format' => $format,
            'orientation' => $orientation,
            'size' => $image->getImageWidth() . 'x' . $image->getImageHeight(),
            'signature' => $image->getImageSignature(),
        ];
    } finally {
        $image->clear();
        $image->destroy();
    }
}

while (($line = fgets(STDIN)) !== false) {
    $parts = explode('|', rtrim($line, "\n"));
    $kind = $parts[0];
    $id = $parts[1] ?? '';

    try {
        if ($kind === 'C') {
            $before = shown($parts[2]);
            $after = shown($parts[3]);
            $result = [
                'id' => $id,
                'ok' => $before['size'] === $after['size'] && $before['signature'] === $after['signature'],
                'before' => $before,
                'after' => $after,
            ];
        } else {
            $image = new Imagick($parts[2]);
            $result = [
                'id' => $id,
                'ok' => $image->getImageWidth() > 0 && $image->getImageHeight() > 0,
                'format' => $image->getImageFormat(),
                'size' => $image->getImageWidth() . 'x' . $image->getImageHeight(),
            ];
            $image->clear();
            $image->destroy();
        }
    } catch (Throwable $e) {
        $result = ['id' => $id, 'ok' => false, 'error' => substr($e->getMessage(), 0, 300)];
    }

    echo json_encode($result, JSON_UNESCAPED_SLASHES), "\n";
    fflush(STDOUT);
}
