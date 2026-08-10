import { cp, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const extensionRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const repositoryRoot = resolve(extensionRoot, '..');
const targets = ['chrome', 'firefox'];
const icons = {
  'icon-16.png': 'public/assets/favicon/favicon-16x16.png',
  'icon-32.png': 'public/assets/favicon/favicon-32x32.png',
  'icon-192.png': 'public/assets/favicon/pwa-192x192.png',
  'icon-512.png': 'public/assets/favicon/pwa-512x512.png',
};
const assets = {
  'logo-landscape-dark.png': 'public/assets/logo/landscape_dark.png',
  'logo-landscape-light.png': 'public/assets/logo/landscape_light.png',
};

for (const target of targets) {
  const output = resolve(extensionRoot, 'build', target);
  await rm(output, { recursive: true, force: true });
  await mkdir(output, { recursive: true });
  await cp(resolve(extensionRoot, 'src'), output, { recursive: true });
  const manifest = JSON.parse(await readFile(resolve(extensionRoot, `manifest.${target}.json`), 'utf8'));
  await writeFile(resolve(output, 'manifest.json'), `${JSON.stringify(manifest, null, 2)}\n`);
  await mkdir(resolve(output, 'icons'), { recursive: true });
  for (const [name, source] of Object.entries(icons)) {
    await cp(resolve(repositoryRoot, source), resolve(output, 'icons', name));
  }
  await mkdir(resolve(output, 'assets'), { recursive: true });
  for (const [name, source] of Object.entries(assets)) {
    await cp(resolve(repositoryRoot, source), resolve(output, 'assets', name));
  }
}

console.log(`Built ${targets.map((target) => `build/${target}`).join(' and ')}`);
