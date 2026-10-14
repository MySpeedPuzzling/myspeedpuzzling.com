/**
 * The little of the participant page's live video that assets/controllers/compete_controller.js needs before the
 * video's own chunk is there (contract phase-1b.md stream VP): whether the round's video is on (the chunk is loaded
 * then), the second screen this device was made, and the waits before a chunk that did not load is asked for again.
 * Everything else lives in compete_video.js / compete_video_page.js - in that chunk, so the page of a round without
 * video carries none of it. Pure - pinned by tests/online-compete-video-harness.mjs.
 */

// A chunk that did not load (offline, a deploy in between) is asked for again after these waits
export const LOAD_RETRY_MS = [2000, 5000, 10000, 20000, 30000];

export const STORAGE_SECOND_SCREEN = 'msp-compete-second-screen:';

/**
 * The round's live video is on (the online timing page's switch, the state's settings) - live as the state changes.
 */
export function videoOn(state) {
    return state?.round?.settings?.video === true;
}

/**
 * This device was made a second screen of this participant (kept on the device: a reload stays one).
 */
export function loadSecondScreen(storage, participantId) {
    try {
        return storage?.getItem(STORAGE_SECOND_SCREEN + participantId) === '1';
    } catch (e) {
        return false;
    }
}

export function saveSecondScreen(storage, participantId, on) {
    try {
        if (on) {
            storage?.setItem(STORAGE_SECOND_SCREEN + participantId, '1');
        } else {
            storage?.removeItem(STORAGE_SECOND_SCREEN + participantId);
        }

        return true;
    } catch (e) {
        return false;
    }
}
