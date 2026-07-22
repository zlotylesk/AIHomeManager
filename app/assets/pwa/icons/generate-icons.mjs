/*
 * One-off generator for the PWA app icons (HMAI-345).
 *
 * Emits full-bleed, opaque PNGs so the same file is safe as both `any` and
 * `maskable` (a maskable icon must fill the whole canvas — the launcher crops
 * it to its own shape, so any transparency or edge padding would show through).
 * The brand mark (a white house, matching the 🏠 favicon) sits inside the
 * centre safe zone, well clear of the crop.
 *
 * Pure Node (no image library): a tiny truecolour-alpha PNG encoder over the
 * built-in zlib. Re-run with `node assets/pwa/icons/generate-icons.mjs` after
 * changing the palette; the committed PNGs are the source of truth for the
 * build and this script only reproduces them.
 */
import { deflateSync } from 'node:zlib';
import { writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const BRAND = [0x25, 0x63, 0xeb]; // --color-primary #2563eb
const WHITE = [0xff, 0xff, 0xff];

const CRC_TABLE = (() => {
    const table = new Int32Array(256);
    for (let n = 0; n < 256; n++) {
        let c = n;
        for (let k = 0; k < 8; k++) {
            c = c & 1 ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
        }
        table[n] = c;
    }
    return table;
})();

function crc32(buf) {
    let c = 0xffffffff;
    for (let i = 0; i < buf.length; i++) {
        c = CRC_TABLE[(c ^ buf[i]) & 0xff] ^ (c >>> 8);
    }
    return (c ^ 0xffffffff) >>> 0;
}

function chunk(type, data) {
    const typeBytes = Buffer.from(type, 'latin1');
    const length = Buffer.alloc(4);
    length.writeUInt32BE(data.length, 0);
    const crc = Buffer.alloc(4);
    crc.writeUInt32BE(crc32(Buffer.concat([typeBytes, data])), 0);
    return Buffer.concat([length, typeBytes, data, crc]);
}

function encodePng(size, pixels) {
    const sig = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]);
    const ihdr = Buffer.alloc(13);
    ihdr.writeUInt32BE(size, 0);
    ihdr.writeUInt32BE(size, 4);
    ihdr[8] = 8; // bit depth
    ihdr[9] = 6; // colour type: truecolour with alpha
    // 10..12 default (deflate / adaptive / no interlace)

    const stride = size * 4;
    const raw = Buffer.alloc(size * (stride + 1));
    for (let y = 0; y < size; y++) {
        raw[y * (stride + 1)] = 0; // filter: none
        pixels.copy(raw, y * (stride + 1) + 1, y * stride, y * stride + stride);
    }

    return Buffer.concat([
        sig,
        chunk('IHDR', ihdr),
        chunk('IDAT', deflateSync(raw, { level: 9 })),
        chunk('IEND', Buffer.alloc(0)),
    ]);
}

function set(pixels, size, x, y, [r, g, b]) {
    if (x < 0 || y < 0 || x >= size || y >= size) {
        return;
    }
    const i = (y * size + x) * 4;
    pixels[i] = r;
    pixels[i + 1] = g;
    pixels[i + 2] = b;
    pixels[i + 3] = 0xff;
}

function drawIcon(size) {
    const pixels = Buffer.alloc(size * size * 4);
    // Full-bleed brand background — opaque, so the file is maskable-safe.
    for (let i = 0; i < size * size; i++) {
        pixels[i * 4] = BRAND[0];
        pixels[i * 4 + 1] = BRAND[1];
        pixels[i * 4 + 2] = BRAND[2];
        pixels[i * 4 + 3] = 0xff;
    }

    const cx = size / 2;
    const hw = size * 0.22; // half-width of the house body — inside the safe zone
    const bodyTop = cx - hw * 0.15;
    const bodyBottom = cx + hw;
    const roofApex = cx - hw * 1.05;
    const eave = bodyTop;
    const roofHalf = hw * 1.2;
    const doorHalf = hw * 0.28;
    const doorTop = bodyBottom - hw * 0.9;

    for (let y = 0; y < size; y++) {
        for (let x = 0; x < size; x++) {
            const dx = x - cx;
            // Roof: filled triangle from the apex down to the eaves.
            if (y >= roofApex && y <= eave) {
                const t = (y - roofApex) / (eave - roofApex);
                const half = roofHalf * t;
                if (Math.abs(dx) <= half) {
                    set(pixels, size, x, y, WHITE);
                }
            }
            // Body.
            if (y > eave && y <= bodyBottom && Math.abs(dx) <= hw) {
                set(pixels, size, x, y, WHITE);
            }
            // Door cut-out (brand colour) inside the body.
            if (y >= doorTop && y <= bodyBottom && Math.abs(dx) <= doorHalf) {
                set(pixels, size, x, y, BRAND);
            }
        }
    }

    return encodePng(size, pixels);
}

const here = dirname(fileURLToPath(import.meta.url));
for (const size of [192, 512]) {
    writeFileSync(join(here, `icon-${size}.png`), drawIcon(size));
}
// Apple applies its own rounded mask, so the same full-bleed art works at 180.
writeFileSync(join(here, 'apple-touch-icon.png'), drawIcon(180));

// eslint-disable-next-line no-console
console.log('Generated icon-192.png, icon-512.png, apple-touch-icon.png');
