import { stat, readFile } from 'node:fs/promises';

const directory = new URL('../wp-content/themes/ai-skills-hub/assets/dist/', import.meta.url);
for (const file of ['app.css', 'app.js']) {
  const path = new URL(file, directory);
  const info = await stat(path);
  if (info.size === 0) throw new Error(`${file} is empty`);
  console.log(`${file}: ${info.size} bytes`);
}
const css = await readFile(new URL('app.css', directory), 'utf8');
if (!css.includes('.bg-slate-50')) throw new Error('Tailwind template scanning failed');
