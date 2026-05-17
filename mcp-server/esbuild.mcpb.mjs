/**
 * MCPB Build Script
 *
 * Bundles the MCP server into a single JS file with all dependencies inlined,
 * then packages it as a .mcpb (ZIP) file for Claude Desktop extension install.
 *
 * Output structure:
 *   dist/mcpb/
 *   ├── manifest.json
 *   ├── icon.png
 *   ├── guides/          ← runtime-loaded markdown files
 *   │   ├── post-guide.md
 *   │   ├── page-guide.md
 *   │   └── generic-guide.md
 *   └── server/
 *       └── index.js     ← single bundled entry point
 */

import { build } from 'esbuild'
import {
  cpSync,
  mkdirSync,
  rmSync,
  existsSync,
  readdirSync,
  statSync,
  readFileSync,
  createWriteStream,
} from 'fs'
import { join, relative } from 'path'
import { fileURLToPath } from 'url'
import { createDeflateRaw } from 'zlib'

const __dirname = fileURLToPath(new URL('.', import.meta.url))
const MCPB_DIR = join(__dirname, 'dist', 'mcpb')
const ASSETS_DEST = join(__dirname, '..', 'wp-plugin', 'wp-mcp-gateway', 'assets')

// ── Step 1: Clean ────────────────────────────────────────────────────────────

if (existsSync(MCPB_DIR)) {
  rmSync(MCPB_DIR, { recursive: true })
}
mkdirSync(MCPB_DIR, { recursive: true })
mkdirSync(join(MCPB_DIR, 'server'), { recursive: true })

// ── Step 2: esbuild bundle ───────────────────────────────────────────────────

await build({
  entryPoints: [join(__dirname, 'dist', 'index.js')],
  outfile: join(MCPB_DIR, 'server', 'index.js'),
  bundle: true,
  platform: 'node',
  target: 'node18',
  format: 'esm',
  // No externals — everything bundled. dotenv is included so the import
  // resolves; dotenv.config() is a no-op when no .env file exists.
  external: [],
  banner: {
    js: [
      '// WP MCP Gateway — bundled for MCPB distribution',
      'import { createRequire } from "module";',
      'const require = createRequire(import.meta.url);',
    ].join('\n'),
  },
})

console.log('✓ esbuild bundle complete')

// ── Step 3: Copy static assets ───────────────────────────────────────────────

// manifest.json + icon.png → mcpb root
cpSync(join(__dirname, 'manifest.json'), join(MCPB_DIR, 'manifest.json'))
cpSync(join(__dirname, 'icon.png'), join(MCPB_DIR, 'icon.png'))

// guides → mcpb root (registerPrompts.js uses ../guides/ relative to prompts/)
// When bundled into server/index.js, __dirname = server/, so ../guides/ = guides/
mkdirSync(join(MCPB_DIR, 'guides'), { recursive: true })
cpSync(join(__dirname, 'dist', 'guides'), join(MCPB_DIR, 'guides'), { recursive: true })

console.log('✓ static assets copied')

// ── Step 4: Build ZIP (.mcpb) ────────────────────────────────────────────────

// Minimal ZIP implementation using Node builtins (no external deps needed)
const mcpbPath = join(ASSETS_DEST, 'axtolab-ai-connector.mcpb')
mkdirSync(ASSETS_DEST, { recursive: true })

/**
 * Collect all files in a directory tree, returning relative paths.
 */
function collectFiles(dir, base = dir) {
  const results = []
  for (const entry of readdirSync(dir)) {
    const full = join(dir, entry)
    const rel = relative(base, full)
    if (statSync(full).isDirectory()) {
      results.push(...collectFiles(full, base))
    } else {
      results.push(rel)
    }
  }
  return results
}

/**
 * Build a ZIP file from a directory using Node builtins.
 * Implements the ZIP format spec (local file headers + central directory).
 */
async function buildZip(sourceDir, outPath) {
  const files = collectFiles(sourceDir)
  const entries = []
  const buffers = []

  let offset = 0

  for (const relPath of files) {
    const fullPath = join(sourceDir, relPath)
    const data = readFileSync(fullPath)
    // Use forward slashes in ZIP paths
    const zipPath = relPath.replace(/\\/g, '/')
    const nameBuffer = Buffer.from(zipPath, 'utf-8')

    // Compress the data
    const compressed = await deflateRaw(data)
    const useCompression = compressed.length < data.length
    const storedData = useCompression ? compressed : data
    const method = useCompression ? 8 : 0 // 8 = deflate, 0 = store

    // CRC32
    const crc = crc32(data)

    // Local file header (30 bytes + name)
    const localHeader = Buffer.alloc(30)
    localHeader.writeUInt32LE(0x04034b50, 0)   // signature
    localHeader.writeUInt16LE(20, 4)            // version needed
    localHeader.writeUInt16LE(0, 6)             // flags
    localHeader.writeUInt16LE(method, 8)        // compression method
    localHeader.writeUInt16LE(0, 10)            // mod time
    localHeader.writeUInt16LE(0, 12)            // mod date
    localHeader.writeUInt32LE(crc, 14)          // crc32
    localHeader.writeUInt32LE(storedData.length, 18) // compressed size
    localHeader.writeUInt32LE(data.length, 22)       // uncompressed size
    localHeader.writeUInt16LE(nameBuffer.length, 26) // filename length
    localHeader.writeUInt16LE(0, 28)            // extra field length

    entries.push({
      offset,
      crc,
      compressedSize: storedData.length,
      uncompressedSize: data.length,
      nameBuffer,
      method,
    })

    buffers.push(localHeader, nameBuffer, storedData)
    offset += localHeader.length + nameBuffer.length + storedData.length
  }

  // Central directory
  const centralStart = offset
  for (const entry of entries) {
    const central = Buffer.alloc(46)
    central.writeUInt32LE(0x02014b50, 0)       // signature
    central.writeUInt16LE(20, 4)               // version made by
    central.writeUInt16LE(20, 6)               // version needed
    central.writeUInt16LE(0, 8)                // flags
    central.writeUInt16LE(entry.method, 10)    // compression method
    central.writeUInt16LE(0, 12)               // mod time
    central.writeUInt16LE(0, 14)               // mod date
    central.writeUInt32LE(entry.crc, 16)       // crc32
    central.writeUInt32LE(entry.compressedSize, 20)  // compressed size
    central.writeUInt32LE(entry.uncompressedSize, 24) // uncompressed size
    central.writeUInt16LE(entry.nameBuffer.length, 28) // filename length
    central.writeUInt16LE(0, 30)               // extra field length
    central.writeUInt16LE(0, 32)               // comment length
    central.writeUInt16LE(0, 34)               // disk number start
    central.writeUInt16LE(0, 36)               // internal attrs
    central.writeUInt32LE(0, 38)               // external attrs
    central.writeUInt32LE(entry.offset, 42)    // local header offset

    buffers.push(central, entry.nameBuffer)
    offset += central.length + entry.nameBuffer.length
  }

  const centralSize = offset - centralStart

  // End of central directory (22 bytes)
  const eocd = Buffer.alloc(22)
  eocd.writeUInt32LE(0x06054b50, 0)            // signature
  eocd.writeUInt16LE(0, 4)                     // disk number
  eocd.writeUInt16LE(0, 6)                     // central dir disk
  eocd.writeUInt16LE(entries.length, 8)        // entries on disk
  eocd.writeUInt16LE(entries.length, 10)       // total entries
  eocd.writeUInt32LE(centralSize, 12)          // central dir size
  eocd.writeUInt32LE(centralStart, 16)         // central dir offset
  eocd.writeUInt16LE(0, 20)                    // comment length

  buffers.push(eocd)

  const zipBuffer = Buffer.concat(buffers)
  const { writeFileSync } = await import('fs')
  writeFileSync(outPath, zipBuffer)
}

/** Promisified zlib.deflateRaw */
function deflateRaw(data) {
  return new Promise((resolve, reject) => {
    const chunks = []
    const deflater = createDeflateRaw()
    deflater.on('data', (chunk) => chunks.push(chunk))
    deflater.on('end', () => resolve(Buffer.concat(chunks)))
    deflater.on('error', reject)
    deflater.end(data)
  })
}

/** CRC32 (standard ZIP CRC) */
function crc32(buf) {
  let crc = 0xFFFFFFFF
  for (let i = 0; i < buf.length; i++) {
    crc ^= buf[i]
    for (let j = 0; j < 8; j++) {
      crc = (crc >>> 1) ^ (crc & 1 ? 0xEDB88320 : 0)
    }
  }
  return (crc ^ 0xFFFFFFFF) >>> 0
}

await buildZip(MCPB_DIR, mcpbPath)

const { statSync: stat } = await import('fs')
const size = stat(mcpbPath).size
const sizeKB = (size / 1024).toFixed(0)
console.log(`✓ ${mcpbPath}`)
console.log(`  Size: ${sizeKB} KB (${(size / 1024 / 1024).toFixed(2)} MB)`)

if (size > 1_000_000) {
  console.warn('⚠ Bundle is over 1MB — check that node_modules is not included')
}
