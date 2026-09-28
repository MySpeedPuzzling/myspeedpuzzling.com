/**
 * Shrinks a photo in the browser before upload: at most 2000 px on the longer side, JPEG.
 * A 5-12 MB phone photo becomes ~0.5 MB - fast on mobile data and far below the server's
 * 10 MB limit. Shared by the add form (submit_prevention_controller) and multiscan quick-add.
 */
export const COMPRESS_THRESHOLD_BYTES = 500 * 1024;
export const MAX_DIMENSION = 2000;
export const JPEG_QUALITY = 0.85;

export function shouldCompress(file) {
    return Boolean(file)
        && file.size > COMPRESS_THRESHOLD_BYTES
        && file.type !== 'image/gif'
        && file.type.startsWith('image/');
}

export function calculateDimensions(originalWidth, originalHeight) {
    let width = originalWidth;
    let height = originalHeight;

    if (width <= MAX_DIMENSION && height <= MAX_DIMENSION) {
        return { width, height };
    }

    if (width > height) {
        height = Math.round(height * (MAX_DIMENSION / width));
        width = MAX_DIMENSION;
    } else {
        width = Math.round(width * (MAX_DIMENSION / height));
        height = MAX_DIMENSION;
    }

    return { width, height };
}

export function compressImage(file) {
    return new Promise((resolve, reject) => {
        const url = URL.createObjectURL(file);
        const img = new Image();

        img.onload = () => {
            URL.revokeObjectURL(url);

            try {
                const { width, height } = calculateDimensions(img.naturalWidth, img.naturalHeight);

                const canvas = document.createElement('canvas');
                canvas.width = width;
                canvas.height = height;

                const ctx = canvas.getContext('2d');
                ctx.drawImage(img, 0, 0, width, height);

                canvas.toBlob(
                    (blob) => {
                        if (!blob) {
                            reject(new Error('Canvas toBlob returned null'));
                            return;
                        }

                        const fileName = file.name.replace(/\.[^.]+$/, '.jpg');
                        resolve(new File([blob], fileName, {
                            type: 'image/jpeg',
                            lastModified: Date.now(),
                        }));
                    },
                    'image/jpeg',
                    JPEG_QUALITY,
                );
            } catch (error) {
                reject(error);
            }
        };

        img.onerror = () => {
            URL.revokeObjectURL(url);
            reject(new Error('Failed to load image'));
        };

        img.src = url;
    });
}

/**
 * The smaller of the original and its compressed copy - never fails: a browser that cannot
 * decode the photo (some HEIC outside Safari) just sends the original.
 */
export async function compressedOrOriginal(file) {
    if (!shouldCompress(file)) {
        return file;
    }

    try {
        const compressed = await compressImage(file);

        return compressed.size < file.size ? compressed : file;
    } catch (error) {
        return file;
    }
}
