/**
 * Shrink a photograph in the browser before it is uploaded.
 *
 * The people using this are on mid-range Android phones over patchy data, and a
 * modern phone camera produces four to eight megabytes a shot. Three photos of
 * a sick bird is twenty megabytes and a booking that never arrives.
 *
 * Drawing to a canvas at a sane size and re-encoding as JPEG takes that to a
 * couple of hundred kilobytes, which is plenty for somebody to look at a comb
 * and a pair of eyes. The original is never sent.
 *
 * Everything here fails soft: if the browser cannot do it — an old WebView, a
 * HEIC the canvas will not decode, an out-of-memory on a very large image — the
 * original file is returned and the server takes it as it is. A photo that
 * uploads slowly beats a photo that does not upload.
 */

/** Longest edge, in pixels. Enough to see detail on a bird, small enough to send. */
const MAX_EDGE = 1600;

/** JPEG quality. Above about 0.8 the file grows fast and looks the same. */
const QUALITY = 0.75;

/** Below this, re-encoding usually makes the file bigger rather than smaller. */
const SKIP_UNDER_BYTES = 300 * 1024;

export async function compressImage(file, { maxEdge = MAX_EDGE, quality = QUALITY } = {}) {
    if (!file?.type?.startsWith('image/')) {
        return file;
    }

    // Already small, or a format canvas will not re-encode usefully.
    if (file.size <= SKIP_UNDER_BYTES || file.type === 'image/gif') {
        return file;
    }

    try {
        const bitmap = await loadBitmap(file);
        const scale = Math.min(1, maxEdge / Math.max(bitmap.width, bitmap.height));

        const width = Math.round(bitmap.width * scale);
        const height = Math.round(bitmap.height * scale);

        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;

        const context = canvas.getContext('2d');
        context.drawImage(bitmap, 0, 0, width, height);

        bitmap.close?.();

        const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));

        // If the round trip made it bigger, keep what we had.
        if (!blob || blob.size >= file.size) {
            return file;
        }

        return new File([blob], renameToJpeg(file.name), {
            type: 'image/jpeg',
            lastModified: Date.now(),
        });
    } catch {
        return file;
    }
}

/**
 * Compress a list, one at a time.
 *
 * Sequential rather than parallel on purpose: decoding four full-size photos at
 * once is what makes a cheap phone kill the tab.
 */
export async function compressAll(files, onProgress) {
    const out = [];

    for (const [index, file] of Array.from(files).entries()) {
        out.push(await compressImage(file));
        onProgress?.(index + 1, files.length);
    }

    return out;
}

async function loadBitmap(file) {
    if (typeof createImageBitmap === 'function') {
        return createImageBitmap(file);
    }

    // Older WebViews: the <img> route, which needs its object URL cleaning up.
    const url = URL.createObjectURL(file);

    try {
        return await new Promise((resolve, reject) => {
            const image = new Image();
            image.onload = () => resolve(image);
            image.onerror = reject;
            image.src = url;
        });
    } finally {
        URL.revokeObjectURL(url);
    }
}

function renameToJpeg(name) {
    return `${(name || 'photo').replace(/\.[^.]+$/, '')}.jpg`;
}

export function readableSize(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}
