import fs from 'node:fs';
import path from 'node:path';
import process from 'node:process';
import beautify from 'js-beautify';

const viewsDirectory = path.resolve('app/Views');
const write = process.argv.includes('--write');
const check = process.argv.includes('--check');

if (write === check) {
  console.error('Use exactly one of --write or --check.');
  process.exit(2);
}

const files = fs.readdirSync(viewsDirectory, { recursive: true })
  .filter((file) => file.endsWith('.html'))
  .map((file) => path.join(viewsDirectory, file))
  .sort();

const options = {
  indent_size: 2,
  end_with_newline: true,
  max_preserve_newlines: 1,
  preserve_newlines: false,
  templating: ['handlebars'],
  wrap_line_length: 120,
};

const unformatted = [];
for (const file of files) {
  const source = fs.readFileSync(file, 'utf8');
  const formatted = beautify.html(source, options);

  if (source === formatted) continue;
  if (write) {
    fs.writeFileSync(file, formatted);
  } else {
    unformatted.push(path.relative(process.cwd(), file));
  }
}

if (unformatted.length) {
  console.error('Unformatted Fat-Free templates:');
  for (const file of unformatted) console.error(`- ${file}`);
  console.error('Run npm run format:views to fix them.');
  process.exit(1);
}

console.log(write
  ? `Formatted ${files.length} Fat-Free templates.`
  : `Checked ${files.length} Fat-Free templates.`);
