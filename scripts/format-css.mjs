import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import beautify from 'js-beautify';

const stylesDirectory = path.resolve('public/assets/css');
const write = process.argv.includes('--write');
const check = process.argv.includes('--check');

if (write === check) {
  console.error('Use exactly one of --write or --check.');
  process.exit(2);
}

const files = fs.readdirSync(stylesDirectory)
  .filter((file) => file.endsWith('.css'))
  .map((file) => path.join(stylesDirectory, file))
  .sort();

const options = {
  indent_size: 2,
  end_with_newline: true,
  newline_between_rules: true,
  selector_separator_newline: true,
};

const unformatted = [];
for (const file of files) {
  const source = fs.readFileSync(file, 'utf8');
  const formatted = beautify.css(source, options);

  if (source === formatted) continue;
  if (write) {
    fs.writeFileSync(file, formatted);
  } else {
    unformatted.push(path.relative(process.cwd(), file));
  }
}

if (unformatted.length) {
  console.error('Unformatted stylesheets:');
  for (const file of unformatted) console.error(`- ${file}`);
  console.error('Run npm run format:css to fix them.');
  process.exit(1);
}

console.log(write
  ? `Formatted ${files.length} stylesheets.`
  : `Checked ${files.length} stylesheets.`);
