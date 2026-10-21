// Makes smaller WebP copies of the website's own photos so browsers download a size that fits the screen.
//
// For every JPG/PNG under public/assets/images (logos and labels excepted) it writes, beside the original:
//   "Header orlena-01.png" -> "Header orlena-01.w480.webp", ".w800.webp", ".w1200.webp"
// Only widths smaller than the original are made, and existing copies are kept unless --force is given.
// The pages pick them up automatically through App\Support\ResponsiveImage (srcset).
//
// Run after adding or replacing a photo in public/assets/images:
//   npm install --no-save sharp@0.33.5 && node scripts/make-image-sizes.mjs
import { readdir, stat } from 'node:fs/promises';
import { join, extname } from 'node:path';
import sharp from 'sharp';

const ROOT = 'public/assets/images';
const WIDTHS = [480, 800, 1200];
const SKIP = /(Orlena-Logo|label)\.png$/i;
// Raw menu photos kept out of git (see .gitignore) are not website images.
const SKIP_DIRS = new Set(['PNG MENU ORLENA']);
const force = process.argv.includes('--force');

async function* photos(dir) {
  for (const entry of await readdir(dir, { withFileTypes: true })) {
    const path = join(dir, entry.name);
    if (entry.isDirectory()) { if (!SKIP_DIRS.has(entry.name)) yield* photos(path); }
    else if (/\.(jpe?g|png)$/i.test(entry.name) && !SKIP.test(entry.name)) yield path;
  }
}
const exists = path => stat(path).then(() => true, () => false);

let made = 0;
for await (const file of photos(ROOT)) {
  const { width } = await sharp(file).metadata();
  const base = file.slice(0, -extname(file).length);
  for (const w of WIDTHS.filter(w => w < width)) {
    const out = `${base}.w${w}.webp`;
    if (!force && await exists(out)) continue;
    await sharp(file).rotate().resize({ width: w }).webp({ quality: 78, effort: 5 }).toFile(out);
    made++;
    console.log(`${out}  ${Math.round((await stat(out)).size / 1024)} KB`);
  }
}
console.log(`${made} file(s) written`);
