/**
 * What a photo's EXIF says, read in the browser before the photo is shrunk (docs/features/upload-image-metadata.md
 * "Read in the browser"). The canvas re-encode of assets/image_compression.js drops all EXIF, so without this most phone
 * photos of the add/edit-time form and multiscan would reach the server with no capture time.
 *
 * Reads the same short allow-list as the server's UploadedImageSummariser, and nothing else:
 * - the original file's size, format (JPEG, or HEIC / AVIF) and stored pixels
 * - whether it has EXIF at all, and EXIF a camera writes (make/model present, exposure, aperture, ISO, focal length)
 * - DateTimeOriginal and OffsetTimeOriginal, the Make and Software strings (the server keeps only the brand and the
 *   Software family of them - BrowserImageSummary), the orientation
 * - whether the user comment labels a screenshot - the comment itself is never sent
 *
 * Never read: the GPS IFD (its pointer is not followed), serial numbers, owner / artist / copyright, maker notes, the
 * thumbnail, the camera model (only whether there is one). Tags are looked up by number; everything else in the block
 * is skipped without being decoded.
 *
 * Never throws and never waits long: anything it cannot read (another format, a broken block, a slow file) gives null,
 * and nothing is sent - the upload goes on exactly as without it. Dependency-free; tests/photo-metadata-harness.mjs
 * runs it under node (tests/PhotoMetadataParityTest.php).
 */

const READ_WINDOW_BYTES = 256 * 1024;
const READ_TIMEOUT_MS = 3000;
// A HEIF `meta` box or Exif item larger than this is not a phone photo's - not read
const MAX_BLOCK_BYTES = 1024 * 1024;
const MAX_TEXT_LENGTH = 128;
const MAX_TIME_LENGTH = 32;

// IFD0
const TAG_MAKE = 0x010F;
const TAG_MODEL = 0x0110;
const TAG_ORIENTATION = 0x0112;
const TAG_SOFTWARE = 0x0131;
const TAG_EXIF_IFD = 0x8769;
// Exif IFD
const TAG_EXPOSURE_TIME = 0x829A;
const TAG_F_NUMBER = 0x829D;
const TAG_ISO = 0x8827;
const TAG_DATE_TIME_ORIGINAL = 0x9003;
const TAG_OFFSET_TIME_ORIGINAL = 0x9011;
const TAG_FOCAL_LENGTH = 0x920A;
const TAG_USER_COMMENT = 0x9286;

// The only tags ever looked at - the GPS pointer (0x8825), serials, owner, maker note and the rest are not among them
const IFD0_TAGS = new Set([TAG_MAKE, TAG_MODEL, TAG_ORIENTATION, TAG_SOFTWARE, TAG_EXIF_IFD]);
const EXIF_TAGS = new Set([TAG_EXPOSURE_TIME, TAG_F_NUMBER, TAG_ISO, TAG_DATE_TIME_ORIGINAL, TAG_OFFSET_TIME_ORIGINAL, TAG_FOCAL_LENGTH, TAG_USER_COMMENT]);

const TYPE_SIZES = { 1: 1, 2: 1, 3: 2, 4: 4, 5: 8, 6: 1, 7: 1, 8: 2, 9: 4, 10: 8, 11: 4, 12: 8 };

// UploadedImageSummariser::SCREENSHOT_PATTERN - iOS and macOS label their screenshots in the user comment
const SCREENSHOT_PATTERN = /screen ?shot|snipping|greenshot|lightshot|sharex|flameshot|spectacle/i;

// Start of frame markers (baseline, progressive, lossless, arithmetic) - they hold the stored pixels
const JPEG_SOF_MARKERS = new Set([0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF]);

const HEIF_BRANDS = new Set(['heic', 'heix', 'heim', 'heis', 'hevc', 'hevx', 'hevm', 'hevs', 'mif1', 'msf1', 'mif2']);
const AVIF_BRANDS = new Set(['avif', 'avis']);

const remembered = new WeakMap();

/**
 * The summary of a picked photo, or null - never throws, gives up after a few seconds.
 *
 * @param {Blob} file
 * @returns {Promise<object|null>}
 */
export async function readPhotoMetadata(file) {
    try {
        if (!file || typeof file.slice !== 'function' || !(file.size > 0)) {
            return null;
        }

        let timer = null;
        const timeout = new Promise((resolve) => {
            timer = setTimeout(() => resolve(null), READ_TIMEOUT_MS);
        });

        try {
            return await Promise.race([summarisePhoto(blobSource(file)).catch(() => null), timeout]);
        } finally {
            clearTimeout(timer);
        }
    } catch (error) {
        return null;
    }
}

/**
 * The summary kept for a file the browser made from a photo: its shrunk copy, or the image editor's result - they are
 * the same photo, whose own EXIF is gone.
 */
export function rememberPhotoMetadata(file, metadata) {
    if (file && metadata) {
        remembered.set(file, metadata);
    }
}

export function photoMetadataOf(file) {
    return (file && remembered.get(file)) || null;
}

/** What is known of the file, else read from it now */
export async function photoMetadataFor(file) {
    const known = photoMetadataOf(file);

    if (known) {
        return known;
    }

    const read = await readPhotoMetadata(file);
    rememberPhotoMetadata(file, read);

    return read;
}

/**
 * @param {{size: number, read: function(number, number): Promise<Uint8Array>}} source
 * @returns {Promise<object|null>} null for a format it does not read
 */
export async function summarisePhoto(source) {
    const head = await source.read(0, 32);

    if (head.length >= 3 && head[0] === 0xFF && head[1] === 0xD8 && head[2] === 0xFF) {
        return jpegSummary(source);
    }

    if (head.length >= 12 && fourCc(head, 4) === 'ftyp') {
        const format = heifFormat(head);

        if (format !== null) {
            return heifSummary(source, format);
        }
    }

    return null;
}

export function bytesSource(bytes) {
    return {
        size: bytes.length,
        read: async (start, end) => bytes.subarray(start, Math.min(end, bytes.length)),
    };
}

function blobSource(blob) {
    let windowStart = 0;
    let window = null;

    return {
        size: blob.size,
        read: async (start, end) => {
            const to = Math.min(end, blob.size);

            if (window === null || start < windowStart || to > windowStart + window.length) {
                windowStart = start;
                window = new Uint8Array(await blob.slice(start, Math.min(blob.size, start + Math.max(to - start, READ_WINDOW_BYTES))).arrayBuffer());
            }

            return window.subarray(start - windowStart, Math.max(start, to) - windowStart);
        },
    };
}

async function readExact(source, start, length) {
    if (start < 0 || length < 0 || start + length > source.size) {
        throw new Error('Out of the file');
    }

    const bytes = await source.read(start, start + length);

    if (bytes.length !== length) {
        throw new Error('Short read');
    }

    return bytes;
}

// ------------------------------------------------------------------ JPEG

async function jpegSummary(source) {
    let offset = 2;
    let tiff = null;
    let width = 0;
    let height = 0;

    while (offset + 4 <= source.size) {
        const header = await readExact(source, offset, 4);

        if (header[0] !== 0xFF) {
            throw new Error('Not a JPEG marker');
        }

        const marker = header[1];

        // Fill bytes, and markers without a length
        if (marker === 0xFF) {
            offset += 1;
            continue;
        }

        if (marker === 0x01 || marker === 0xD8 || (marker >= 0xD0 && marker <= 0xD7)) {
            offset += 2;
            continue;
        }

        // The image data starts (or ends) - every APPn and the frame header come before it
        if (marker === 0xDA || marker === 0xD9) {
            break;
        }

        const length = (header[2] << 8) | header[3];

        if (length < 2) {
            throw new Error('Broken JPEG segment');
        }

        if (marker === 0xE1 && tiff === null && length >= 8) {
            const segment = await readExact(source, offset + 4, length - 2);

            // An APP1 also holds XMP - only the one starting with the EXIF header is the TIFF block
            if (ascii(segment, 0, 6) === 'Exif\0\0') {
                tiff = segment.subarray(6);
            }
        } else if (JPEG_SOF_MARKERS.has(marker)) {
            const frame = await readExact(source, offset + 4, 5);
            height = (frame[1] << 8) | frame[2];
            width = (frame[3] << 8) | frame[4];
            break;
        }

        offset += 2 + length;
    }

    if (width < 1 || height < 1) {
        return null;
    }

    return summary('JPEG', source.size, width, height, tiff);
}

// ------------------------------------------------------------------ HEIF (HEIC, AVIF)

function heifFormat(head) {
    const view = new DataView(head.buffer, head.byteOffset, head.byteLength);
    const size = Math.min(view.getUint32(0), head.length);
    const brands = [fourCc(head, 8)];

    for (let position = 16; position + 4 <= size; position += 4) {
        brands.push(fourCc(head, position));
    }

    // An AVIF lists mif1 too - its major brand tells it apart
    if (AVIF_BRANDS.has(brands[0])) {
        return 'AVIF';
    }

    if (brands.some(brand => HEIF_BRANDS.has(brand))) {
        return 'HEIC';
    }

    return brands.some(brand => AVIF_BRANDS.has(brand)) ? 'AVIF' : null;
}

async function heifSummary(source, format) {
    // The top-level boxes up to `meta` (ftyp first, meta right after it in every phone's file)
    let offset = 0;
    let meta = null;

    while (offset + 8 <= source.size) {
        const header = await readExact(source, offset, Math.min(16, source.size - offset));
        const box = boxHeader(header, 0, source.size - offset);

        if (box === null) {
            throw new Error('Broken box');
        }

        if (box.type === 'meta') {
            meta = { start: offset, size: box.size, headerSize: box.headerSize };
            break;
        }

        offset += box.size;
    }

    if (meta === null || meta.size > MAX_BLOCK_BYTES) {
        return null;
    }

    const bytes = await readExact(source, meta.start, meta.size);
    // A full box: version + flags before its children
    const children = childBoxes(bytes, meta.headerSize + 4, bytes.length);

    let primaryId = null;
    let exifId = null;
    const locations = new Map();
    let properties = [];
    const associations = new Map();
    let idat = null;

    for (const box of children) {
        const view = viewOf(bytes);

        if (box.type === 'pitm') {
            primaryId = bytes[box.body] === 0 ? view.getUint16(box.body + 4) : view.getUint32(box.body + 4);
        } else if (box.type === 'iinf') {
            exifId = exifItemId(bytes, box);
        } else if (box.type === 'iloc') {
            readItemLocations(bytes, box, locations);
        } else if (box.type === 'iprp') {
            for (const child of childBoxes(bytes, box.body, box.end)) {
                if (child.type === 'ipco') {
                    properties = childBoxes(bytes, child.body, child.end);
                } else if (child.type === 'ipma') {
                    readAssociations(bytes, child, associations);
                }
            }
        } else if (box.type === 'idat') {
            idat = meta.start + box.body;
        }
    }

    const [width, height] = imageSize(bytes, properties, associations, primaryId);

    if (width < 1 || height < 1) {
        return null;
    }

    let tiff = null;
    const location = exifId !== null ? locations.get(exifId) : undefined;

    if (location !== undefined) {
        const item = await itemBytes(source, location, idat);
        const headerOffset = viewOf(item).getUint32(0);
        tiff = item.subarray(4 + headerOffset);

        if (ascii(tiff, 0, 6) === 'Exif\0\0') {
            tiff = tiff.subarray(6);
        }
    }

    return summary(format, source.size, width, height, tiff);
}

function boxHeader(bytes, position, available) {
    if (available < 8 || position + 8 > bytes.length) {
        return null;
    }

    const view = viewOf(bytes);
    let size = view.getUint32(position);
    const type = fourCc(bytes, position + 4);
    let headerSize = 8;

    if (size === 1) {
        if (position + 16 > bytes.length) {
            return null;
        }

        size = view.getUint32(position + 8) * 0x100000000 + view.getUint32(position + 12);
        headerSize = 16;
    } else if (size === 0) {
        size = available;
    }

    if (size < headerSize || size > available) {
        return null;
    }

    return { type, size, headerSize };
}

function childBoxes(bytes, from, to) {
    const boxes = [];
    let position = from;

    while (position + 8 <= to) {
        const box = boxHeader(bytes, position, to - position);

        if (box === null) {
            break;
        }

        boxes.push({ type: box.type, start: position, body: position + box.headerSize, end: position + box.size });
        position += box.size;
    }

    return boxes;
}

function exifItemId(bytes, iinf) {
    const view = viewOf(bytes);
    const version = bytes[iinf.body];
    const entriesFrom = iinf.body + 4 + (version === 0 ? 2 : 4);

    for (const infe of childBoxes(bytes, entriesFrom, iinf.end)) {
        const infeVersion = bytes[infe.body];

        if (infe.type !== 'infe' || infeVersion < 2) {
            continue;
        }

        const id = infeVersion === 2 ? view.getUint16(infe.body + 4) : view.getUint32(infe.body + 4);
        const typeAt = infe.body + 4 + (infeVersion === 2 ? 2 : 4) + 2;

        if (typeAt + 4 <= infe.end && fourCc(bytes, typeAt) === 'Exif') {
            return id;
        }
    }

    return null;
}

function readItemLocations(bytes, iloc, locations) {
    const version = bytes[iloc.body];
    let position = iloc.body + 4;
    const sizes = (bytes[position] << 8) | bytes[position + 1];
    position += 2;

    const offsetSize = (sizes >> 12) & 0xF;
    const lengthSize = (sizes >> 8) & 0xF;
    const baseOffsetSize = (sizes >> 4) & 0xF;
    const indexSize = version === 1 || version === 2 ? sizes & 0xF : 0;
    const readNumber = (size) => {
        if (position + size > iloc.end) {
            throw new Error('Broken iloc');
        }

        let value = 0;

        for (let index = 0; index < size; index++) {
            value = value * 256 + bytes[position + index];
        }

        position += size;

        return value;
    };

    const itemCount = version < 2 ? readNumber(2) : readNumber(4);

    for (let item = 0; item < itemCount; item++) {
        const id = version < 2 ? readNumber(2) : readNumber(4);
        const method = version === 1 || version === 2 ? readNumber(2) & 0xF : 0;
        readNumber(2); // data_reference_index
        const baseOffset = readNumber(baseOffsetSize);
        const extentCount = readNumber(2);
        const extents = [];

        for (let extent = 0; extent < extentCount; extent++) {
            if (indexSize > 0) {
                readNumber(indexSize);
            }

            extents.push({ offset: baseOffset + readNumber(offsetSize), length: readNumber(lengthSize) });
        }

        locations.set(id, { method, extents });
    }
}

function readAssociations(bytes, ipma, associations) {
    const view = viewOf(bytes);
    const version = bytes[ipma.body];
    const wide = (bytes[ipma.body + 3] & 1) === 1;
    let position = ipma.body + 4;
    const entryCount = view.getUint32(position);
    position += 4;

    for (let entry = 0; entry < entryCount && position < ipma.end; entry++) {
        const id = version < 1 ? view.getUint16(position) : view.getUint32(position);
        position += version < 1 ? 2 : 4;
        const count = bytes[position];
        position += 1;
        const indexes = [];

        for (let association = 0; association < count; association++) {
            indexes.push(wide ? view.getUint16(position) & 0x7FFF : bytes[position] & 0x7F);
            position += wide ? 2 : 1;
        }

        associations.set(id, indexes);
    }
}

/**
 * The primary image's `ispe`, turned by its `irot` like the server's reader (libheif) turns it - else the largest one
 * in the file
 */
function imageSize(bytes, properties, associations, primaryId) {
    const view = viewOf(bytes);
    const sizeOf = (box) => (box && box.type === 'ispe' && box.body + 12 <= box.end)
        ? [view.getUint32(box.body + 4), view.getUint32(box.body + 8)]
        : null;
    const primary = (associations.get(primaryId) || []).map(index => properties[index - 1]).filter(Boolean);
    // A quarter or three quarters turn swaps the sides
    const turned = primary.some(box => box.type === 'irot' && box.body < box.end && (bytes[box.body] & 1) === 1);

    for (const box of primary) {
        const size = sizeOf(box);

        if (size !== null) {
            return turned ? [size[1], size[0]] : size;
        }
    }

    let largest = [0, 0];

    for (const box of properties) {
        const size = sizeOf(box);

        if (size !== null && size[0] * size[1] > largest[0] * largest[1]) {
            largest = size;
        }
    }

    return largest;
}

async function itemBytes(source, location, idat) {
    const total = location.extents.reduce((sum, extent) => sum + extent.length, 0);

    if (total < 4 || total > MAX_BLOCK_BYTES || (location.method === 1 && idat === null) || location.method > 1) {
        throw new Error('Exif item not readable');
    }

    const item = new Uint8Array(total);
    let filled = 0;

    for (const extent of location.extents) {
        const start = (location.method === 1 ? idat : 0) + extent.offset;
        item.set(await readExact(source, start, extent.length), filled);
        filled += extent.length;
    }

    return item;
}

// ------------------------------------------------------------------ EXIF (the TIFF block)

function summary(format, bytes, width, height, tiff) {
    const result = { bytes, format, width, height, exif: false, cameraExif: false };

    if (tiff === null) {
        return result;
    }

    const tags = readTiff(tiff);
    const make = tags.text(TAG_MAKE, MAX_TEXT_LENGTH);
    const model = tags.text(TAG_MODEL, MAX_TEXT_LENGTH);
    const software = tags.text(TAG_SOFTWARE, MAX_TEXT_LENGTH);
    const dateTimeOriginal = tags.text(TAG_DATE_TIME_ORIGINAL, MAX_TIME_LENGTH);
    const offsetTimeOriginal = tags.text(TAG_OFFSET_TIME_ORIGINAL, MAX_TIME_LENGTH);
    const orientation = tags.integer(TAG_ORIENTATION);

    result.exif = true;
    // UploadedImageSummariser::CAPTURE_TAGS - the model only counts as being there, it is never sent
    result.cameraExif = make !== null || model !== null
        || [TAG_EXPOSURE_TIME, TAG_F_NUMBER, TAG_ISO, TAG_FOCAL_LENGTH].some(tag => tags.has(tag));

    if (dateTimeOriginal !== null) {
        result.dateTimeOriginal = dateTimeOriginal;
    }

    if (offsetTimeOriginal !== null) {
        result.offsetTimeOriginal = offsetTimeOriginal;
    }

    if (software !== null) {
        result.software = software;
    }

    if (make !== null) {
        result.make = make;
    }

    if (orientation !== null && orientation >= 1 && orientation <= 8) {
        result.orientation = orientation;
    }

    if (tags.screenshotComment()) {
        result.screenshot = true;
    }

    return result;
}

function readTiff(tiff) {
    const view = viewOf(tiff);
    const order = ascii(tiff, 0, 2);

    if ((order !== 'II' && order !== 'MM') || tiff.length < 8) {
        throw new Error('Not a TIFF block');
    }

    const little = order === 'II';
    const u16 = (position) => view.getUint16(position, little);
    const u32 = (position) => view.getUint32(position, little);

    if (u16(2) !== 42) {
        throw new Error('Not a TIFF block');
    }

    // Only the wanted tags of an IFD, each as where its value lies - nothing is decoded here
    const readIfd = (offset, wanted, into) => {
        if (offset < 8 || offset + 2 > tiff.length) {
            return;
        }

        const count = u16(offset);

        for (let index = 0; index < count; index++) {
            const entry = offset + 2 + index * 12;

            if (entry + 12 > tiff.length) {
                return;
            }

            const tag = u16(entry);

            if (!wanted.has(tag)) {
                continue;
            }

            const type = u16(entry + 2);
            const valueCount = u32(entry + 4);
            const size = (TYPE_SIZES[type] || 0) * valueCount;

            if (size === 0) {
                continue;
            }

            const at = size <= 4 ? entry + 8 : u32(entry + 8);

            if (at + size <= tiff.length) {
                into.set(tag, { type, count: valueCount, at, size });
            }
        }
    };

    const entries = new Map();
    readIfd(u32(4), IFD0_TAGS, entries);

    const exifPointer = entries.get(TAG_EXIF_IFD);

    if (exifPointer && exifPointer.type === 4) {
        readIfd(u32(exifPointer.at), EXIF_TAGS, entries);
    }

    return {
        has: (tag) => entries.has(tag),
        text: (tag, maxLength) => {
            const entry = entries.get(tag);

            if (!entry || entry.type !== 2) {
                return null;
            }

            return cleanText(tiff.subarray(entry.at, entry.at + entry.size), maxLength);
        },
        integer: (tag) => {
            const entry = entries.get(tag);

            if (!entry || entry.count !== 1) {
                return null;
            }

            if (entry.type === 3) {
                return u16(entry.at);
            }

            return entry.type === 4 ? u32(entry.at) : null;
        },
        screenshotComment: () => {
            const entry = entries.get(TAG_USER_COMMENT);

            if (!entry || entry.size <= 8) {
                return false;
            }

            // An 8-byte character code, then the text - only looked at for the screenshot label, then dropped
            const code = ascii(tiff, entry.at, 7);
            const text = tiff.subarray(entry.at + 8, entry.at + Math.min(entry.size, 8 + 256));
            const decoded = code === 'UNICODE'
                ? new TextDecoder(little ? 'utf-16le' : 'utf-16be').decode(text)
                : latin1(text);

            return SCREENSHOT_PATTERN.test(decoded);
        },
    };
}

/** An ASCII tag as the server reads it: up to its first NUL, no control characters, trimmed, at most $maxLength */
function cleanText(bytes, maxLength) {
    const end = bytes.indexOf(0);
    const text = new TextDecoder('utf-8').decode(end === -1 ? bytes : bytes.subarray(0, end))
        // eslint-disable-next-line no-control-regex
        .replace(/[\u0000-\u001F\u007F�]/g, '')
        .trim();
    const cut = [...text].slice(0, maxLength).join('').trim();

    return cut !== '' ? cut : null;
}

function viewOf(bytes) {
    return new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
}

function fourCc(bytes, position) {
    return ascii(bytes, position, 4);
}

function ascii(bytes, position, length) {
    if (position + length > bytes.length) {
        return '';
    }

    return String.fromCharCode(...bytes.subarray(position, position + length));
}

function latin1(bytes) {
    let text = '';

    for (const byte of bytes) {
        text += String.fromCharCode(byte);
    }

    return text;
}
