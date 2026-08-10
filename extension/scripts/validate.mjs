import { readdir, readFile, stat } from 'node:fs/promises';
import { dirname, extname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawnSync } from 'node:child_process';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');

async function files(directory) {
  const found = [];
  for (const entry of await readdir(directory)) {
    const path = resolve(directory, entry);
    if ((await stat(path)).isDirectory()) found.push(...await files(path));
    else found.push(path);
  }
  return found;
}

for (const browser of ['chrome', 'firefox']) {
  const output = resolve(root, 'build', browser);
  const manifest = JSON.parse(await readFile(resolve(output, 'manifest.json'), 'utf8'));
  if (manifest.manifest_version !== 3) throw new Error(`${browser} manifest is not MV3.`);
  if (!manifest.host_permissions?.includes('https://catch.sorkos.net/*')) throw new Error(`${browser} is missing the Catch host permission.`);
  for (const path of await files(output)) {
    if (extname(path) !== '.js') continue;
    const check = spawnSync(process.execPath, ['--check', path], { encoding: 'utf8' });
    if (check.status !== 0) throw new Error(`${path}\n${check.stderr}`);
  }
}

console.log('Validated both MV3 builds and all extension JavaScript.');
