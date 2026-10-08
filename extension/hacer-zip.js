// Empaqueta flow-helper (SnaveCloud) en un ZIP que DICloak acepta: rutas en "/",
// deflate estandar, sin carpetas ni BOM. Mismo metodo que la otra extension.
//   node hacer-zip.js
const fs = require('fs');
const zlib = require('zlib');

const base = __dirname + '/';
function leerSinBOM(ruta) {
  let b = fs.readFileSync(ruta);
  if (b.length >= 3 && b[0] === 0xEF && b[1] === 0xBB && b[2] === 0xBF) b = b.slice(3);
  return b;
}
const version = JSON.parse(leerSinBOM(base + 'manifest.json').toString('utf8')).version;
const OUT = base + 'dist/SnaveCloud-Flow-' + version + '.zip';
const ARCHIVOS = [
  'manifest.json', 'background.js', 'cola.js', 'fill.js', 'ia.js', 'panel.js',
  'tanda.js', 'util.js', 'popup.html', 'popup.css', 'popup.js', 'conexion.json',
];

function crc32(b) {
  const t = [];
  for (let i = 0; i < 256; i++) { let v = i; for (let j = 0; j < 8; j++) v = (v & 1) ? 0xEDB88320 ^ (v >>> 1) : v >>> 1; t[i] = v; }
  let c = 0xFFFFFFFF;
  for (let i = 0; i < b.length; i++) c = t[(c ^ b[i]) & 0xFF] ^ (c >>> 8);
  return (c ^ 0xFFFFFFFF) >>> 0;
}

const entries = [], chunks = [];
let offset = 0;
function add(name, data) {
  const comp = zlib.deflateRawSync(data, { level: 6 });
  const crc = crc32(data);
  const fn = Buffer.from(name, 'utf8');
  const lh = Buffer.alloc(30 + fn.length);
  lh.writeUInt32LE(0x04034b50, 0); lh.writeUInt16LE(20, 4); lh.writeUInt16LE(0, 6);
  lh.writeUInt16LE(8, 8); lh.writeUInt16LE(0, 10); lh.writeUInt16LE(0, 12);
  lh.writeUInt32LE(crc, 14); lh.writeUInt32LE(comp.length, 18); lh.writeUInt32LE(data.length, 22);
  lh.writeUInt16LE(fn.length, 26); lh.writeUInt16LE(0, 28); fn.copy(lh, 30);
  entries.push({ fn, crc, compSize: comp.length, uncompSize: data.length, offset });
  chunks.push(lh, comp);
  offset += lh.length + comp.length;
}

for (const n of ARCHIVOS) add(n, leerSinBOM(base + n));

const cdStart = offset;
for (const e of entries) {
  const cd = Buffer.alloc(46 + e.fn.length);
  cd.writeUInt32LE(0x02014b50, 0); cd.writeUInt16LE(20, 4); cd.writeUInt16LE(20, 6);
  cd.writeUInt16LE(0, 8); cd.writeUInt16LE(8, 10); cd.writeUInt16LE(0, 12); cd.writeUInt16LE(0, 14);
  cd.writeUInt32LE(e.crc, 16); cd.writeUInt32LE(e.compSize, 20); cd.writeUInt32LE(e.uncompSize, 24);
  cd.writeUInt16LE(e.fn.length, 28); cd.writeUInt16LE(0, 30); cd.writeUInt16LE(0, 32);
  cd.writeUInt16LE(0, 34); cd.writeUInt16LE(0, 36); cd.writeUInt32LE(0, 38);
  cd.writeUInt32LE(e.offset, 42); e.fn.copy(cd, 46);
  chunks.push(cd); offset += cd.length;
}
const eocd = Buffer.alloc(22);
eocd.writeUInt32LE(0x06054b50, 0); eocd.writeUInt16LE(0, 4); eocd.writeUInt16LE(0, 6);
eocd.writeUInt16LE(entries.length, 8); eocd.writeUInt16LE(entries.length, 10);
eocd.writeUInt32LE(offset - cdStart, 12); eocd.writeUInt32LE(cdStart, 16); eocd.writeUInt16LE(0, 20);
chunks.push(eocd);

fs.mkdirSync(base + 'dist', { recursive: true });
fs.writeFileSync(OUT, Buffer.concat(chunks));
console.log('ZIP creado: ' + OUT + '  (' + entries.length + ' archivos, v' + version + ')');
