import fs from 'fs/promises'
import os from 'os'
import path from 'path'

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

export interface SiteCredentials {
  siteUrl: string
  wpPluginBaseUrl: string
  username: string
  appPassword: string
  siteName: string
  connectedAt: string
}

interface CredentialsFile {
  sites: Record<string, SiteCredentials>
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function storePath(): string {
  return path.join(os.homedir(), '.axtolab-ai-connector', 'credentials.json')
}

function storeDir(): string {
  return path.join(os.homedir(), '.axtolab-ai-connector')
}

async function readFile(): Promise<CredentialsFile> {
  const filePath = storePath()
  try {
    const raw = await fs.readFile(filePath, 'utf-8')
    return JSON.parse(raw) as CredentialsFile
  } catch (err: unknown) {
    // Missing file or parse error — return empty store
    if (
      err instanceof Error &&
      'code' in err &&
      (err as NodeJS.ErrnoException).code === 'ENOENT'
    ) {
      return { sites: {} }
    }
    // Corrupt JSON — return empty rather than crashing
    return { sites: {} }
  }
}

async function writeFile(data: CredentialsFile): Promise<void> {
  const dir = storeDir()
  const filePath = storePath()

  // Ensure directory exists
  await fs.mkdir(dir, { recursive: true })

  // Write atomically by writing to a temp file then renaming
  const tmpPath = `${filePath}.tmp`
  const json = JSON.stringify(data, null, 2)
  await fs.writeFile(tmpPath, json, { encoding: 'utf-8', mode: 0o600 })
  await fs.rename(tmpPath, filePath)

  // Ensure the final file has restricted permissions (0600)
  await fs.chmod(filePath, 0o600)
}

// ---------------------------------------------------------------------------
// Public API
// ---------------------------------------------------------------------------

/**
 * Save or update credentials for a given hostname.
 * The hostname is the bare hostname used as the lookup key (e.g. "example.com").
 */
export async function saveCredentials(
  hostname: string,
  creds: SiteCredentials
): Promise<void> {
  const data = await readFile()
  data.sites[hostname] = creds
  await writeFile(data)
}

/**
 * Load credentials for a given hostname.
 * Returns null if no entry exists.
 */
export async function loadCredentials(
  hostname: string
): Promise<SiteCredentials | null> {
  const data = await readFile()
  return data.sites[hostname] ?? null
}

/**
 * Return all stored sites as an array of { hostname, ...creds } objects.
 */
export async function listSites(): Promise<
  Array<{ hostname: string } & SiteCredentials>
> {
  const data = await readFile()
  return Object.entries(data.sites).map(([hostname, creds]) => ({
    hostname,
    ...creds,
  }))
}

/**
 * Remove credentials for a given hostname.
 * No-op if the hostname is not stored.
 */
export async function removeCredentials(hostname: string): Promise<void> {
  const data = await readFile()
  delete data.sites[hostname]
  await writeFile(data)
}
