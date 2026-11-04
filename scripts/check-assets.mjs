import { stat, readFile } from 'node:fs/promises';

const directory = new URL('../wp-content/themes/ai-skills-hub/assets/dist/', import.meta.url);
for (const file of ['app.css', 'app.js']) {
  const path = new URL(file, directory);
  const info = await stat(path);
  if (info.size === 0) throw new Error(`${file} is empty`);
  console.log(`${file}: ${info.size} bytes`);
}
const css = await readFile(new URL('app.css', directory), 'utf8');
// This utility is used by the current templates; the Phase 0 placeholder was removed.
if (!css.includes('.text-4xl') || !css.includes('.aish-container')) {
  throw new Error('Tailwind template scanning or design-system build failed');
}
