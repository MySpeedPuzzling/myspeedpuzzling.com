// Runs assets/photo_metadata.js (what the browser reads from a photo's EXIF before shrinking it) on the photos
// tests/PhotoMetadataParityTest.php hands over on stdin (base64), each as a Blob like a picked file, and prints what the
// browser would send - the summary object, or null - as JSON.

import { readFileSync } from 'node:fs';
import { readPhotoMetadata } from '../assets/photo_metadata.js';

const photos = JSON.parse(readFileSync(0, 'utf8'));
const results = [];

for (const photo of photos) {
    results.push(await readPhotoMetadata(new Blob([Buffer.from(photo, 'base64')])));
}

process.stdout.write(JSON.stringify(results));
