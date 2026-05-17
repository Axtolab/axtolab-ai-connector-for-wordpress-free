import readline from 'readline'
import fs from 'fs/promises'
import os from 'os'
import path from 'path'
import { saveCredentials, listSites, loadCredentials, removeCredentials } from './tokenStore.js'
import { decodeConnectionToken, isConnectionToken } from './tokenDecoder.js'

// ---------------------------------------------------------------------------
// Types for the device-auth REST responses
// ---------------------------------------------------------------------------

interface DeviceCodeResponse {
  device_code: string
  user_code: string
  expires_in?: number
}

interface DeviceTokenPending {
  status: 'pending'
}

interface DeviceTokenExpired {
  status: 'expired'
}

interface DeviceTokenApproved {
  status: 'approved'
  service_user: string
  token: string
  site_name: string
  wp_plugin_base_url: string
}

type DeviceTokenResponse =
  | DeviceTokenPending
  | DeviceTokenExpired
  | DeviceTokenApproved

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/** Prompt the user with a question and return their answer. */
function prompt(rl: readline.Interface, question: string): Promise<string> {
  return new Promise((resolve) => {
    rl.question(question, (answer) => resolve(answer.trim()))
  })
}

/**
 * Normalise whatever the user typed into a valid https:// URL with no trailing slash.
 * Handles:
 *   - bare hostname:  example.com          → https://example.com
 *   - http URL:       http://example.com/  → https://example.com
 *   - https URL:      https://example.com/ → https://example.com
 */
function normaliseSiteUrl(input: string): string {
  let url = input.trim()

  // Strip trailing slashes early so the URL constructor works cleanly
  url = url.replace(/\/+$/, '')

  // Prepend https:// when there is no scheme at all
  if (!/^https?:\/\//i.test(url)) {
    url = `https://${url}`
  }

  // Upgrade http → https
  url = url.replace(/^http:\/\//i, 'https://')

  // Parse and reconstruct to normalise (lowercases host, etc.)
  try {
    const parsed = new URL(url)
    // Drop trailing slash from pathname
    const pathname = parsed.pathname.replace(/\/+$/, '')
    return `${parsed.protocol}//${parsed.host}${pathname}`
  } catch {
    // If URL is still unparseable, return our best effort
    return url
  }
}

/** Extract the hostname from a normalised site URL. */
function hostnameFrom(siteUrl: string): string {
  try {
    return new URL(siteUrl).hostname
  } catch {
    // Fallback: strip the scheme and use everything before the first /
    return siteUrl.replace(/^https?:\/\//i, '').split('/')[0]
  }
}

/** Sleep for `ms` milliseconds. */
function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

/**
 * Return true if an mcpServers entry references the axtolab-ai-connector package
 * in its command or args fields.
 */
function entryRefsWpMcp(entry: unknown): boolean {
  if (typeof entry !== 'object' || entry === null) return false
  const e = entry as Record<string, unknown>
  const command = typeof e['command'] === 'string' ? e['command'] : ''
  const args = Array.isArray(e['args']) ? e['args'] : []
  return (
    command.includes('axtolab-ai-connector') ||
    args.some((a: unknown) => typeof a === 'string' && a.includes('axtolab-ai-connector'))
  )
}

/**
 * Return true if an mcpServers entry targets the given hostname via its env vars.
 */
function entryTargetsHostname(entry: unknown, hostname: string): boolean {
  if (typeof entry !== 'object' || entry === null) return false
  const e = entry as Record<string, unknown>
  const env =
    typeof e['env'] === 'object' && e['env'] !== null
      ? (e['env'] as Record<string, unknown>)
      : {}
  const site = typeof env['WP_MCP_SITE'] === 'string' ? env['WP_MCP_SITE'] : ''
  const baseUrl =
    typeof env['WP_PLUGIN_BASE_URL'] === 'string' ? env['WP_PLUGIN_BASE_URL'] : ''
  return site === hostname || baseUrl.includes(hostname)
}

/**
 * Merge an entry into ~/.claude/mcp.json.
 *
 * Writes a SINGLE unified "wordpress" entry with no WP_MCP_SITE env.
 * The MCP server loads all connected sites from the token store at startup
 * and supports runtime switching via wp_switch_site.
 *
 * Also cleans up old per-site entries (e.g. "wordpress-example.com") that
 * were created by earlier versions.
 */
async function writeMcpJson(_hostname: string): Promise<void> {
  const mcpJsonPath = path.join(os.homedir(), '.claude', 'mcp.json')

  let existing: Record<string, unknown> = {}
  try {
    const raw = await fs.readFile(mcpJsonPath, 'utf-8')
    const parsed: unknown = JSON.parse(raw)
    if (parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed)) {
      existing = parsed as Record<string, unknown>
    }
  } catch {
    // File missing or corrupt — start fresh
  }

  // Ensure mcpServers key exists
  const mcpServers =
    existing['mcpServers'] !== null &&
    typeof existing['mcpServers'] === 'object' &&
    !Array.isArray(existing['mcpServers'])
      ? (existing['mcpServers'] as Record<string, unknown>)
      : {}

  // Remove ALL old per-site axtolab-ai-connector entries (wordpress-{hostname} pattern).
  // The unified "wordpress" entry replaces them all.
  for (const key of Object.keys(mcpServers)) {
    if (key === 'wordpress') continue
    const entry = mcpServers[key]
    if (entryRefsWpMcp(entry)) {
      console.log(`Removing old single-site entry: ${key}`)
      delete mcpServers[key]
    }
  }

  // Write the unified multi-site entry (no WP_MCP_SITE — loads all from token store)
  mcpServers['wordpress'] = {
    command: process.execPath,
    args: process.argv[1] ? [process.argv[1]] : [],
    env: {},
  }

  existing['mcpServers'] = mcpServers

  // Ensure directory exists
  await fs.mkdir(path.dirname(mcpJsonPath), { recursive: true })
  await fs.writeFile(mcpJsonPath, JSON.stringify(existing, null, 2), 'utf-8')
}

/**
 * Remove the axtolab-ai-connector entry from ~/.claude/mcp.json when the last
 * site is removed. Also cleans up any legacy per-site entries.
 */
async function removeFromMcpJson(_hostname: string): Promise<void> {
  const mcpJsonPath = path.join(os.homedir(), '.claude', 'mcp.json')

  let existing: Record<string, unknown> = {}
  try {
    const raw = await fs.readFile(mcpJsonPath, 'utf-8')
    const parsed: unknown = JSON.parse(raw)
    if (parsed !== null && typeof parsed === 'object' && !Array.isArray(parsed)) {
      existing = parsed as Record<string, unknown>
    }
  } catch {
    return // Nothing to remove
  }

  if (
    existing['mcpServers'] !== null &&
    typeof existing['mcpServers'] === 'object' &&
    !Array.isArray(existing['mcpServers'])
  ) {
    const mcpServers = existing['mcpServers'] as Record<string, unknown>

    // Remove legacy per-site entry if it exists
    delete mcpServers[`wordpress-${_hostname}`]

    // Check if there are any remaining sites in the token store.
    // If none remain, remove the unified "wordpress" entry too.
    const remaining = await listSites()
    if (remaining.length === 0) {
      delete mcpServers['wordpress']
    }

    existing['mcpServers'] = mcpServers
    await fs.writeFile(mcpJsonPath, JSON.stringify(existing, null, 2), 'utf-8')
  }
}

// ---------------------------------------------------------------------------
// Main exported function
// ---------------------------------------------------------------------------

export async function runSetup(siteUrlArg?: string): Promise<void> {
  let rl: readline.Interface | null = null

  try {
    // -----------------------------------------------------------------------
    // Step 1 — Determine the site URL (from argument or interactive prompt)
    // -----------------------------------------------------------------------
    let siteUrl: string
    let hostname: string

    if (siteUrlArg && siteUrlArg.trim()) {
      // Non-interactive mode: URL provided as CLI argument
      siteUrl = normaliseSiteUrl(siteUrlArg)
      hostname = hostnameFrom(siteUrl)
    } else {
      // Interactive mode: prompt the user
      rl = readline.createInterface({
        input: process.stdin,
        output: process.stdout,
      })
      const rawUrl = await prompt(rl, 'What is your WordPress site URL? ')
      if (!rawUrl) {
        console.error('No URL provided. Exiting.')
        process.exit(1)
      }
      siteUrl = normaliseSiteUrl(rawUrl)
      hostname = hostnameFrom(siteUrl)
    }

    // -----------------------------------------------------------------------
    // Step 2 — POST /wp-json/axtolab-ai-connector/v1/device/code
    // -----------------------------------------------------------------------
    const deviceCodeEndpoint = `${siteUrl}/wp-json/axtolab-ai-connector/v1/device/code`

    // Build a descriptive client label for the Connection Manager.
    const clientType = process.env.CLAUDE_DESKTOP ? 'Claude Desktop'
        : process.env.CLAUDE_COWORK ? 'Cowork'
        : 'CLI'
    const clientLabel = `${clientType} — ${os.hostname()}`

    let deviceCodeData: DeviceCodeResponse
    try {
      const res = await fetch(deviceCodeEndpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ client_label: clientLabel }),
      })

      if (res.status === 404) {
        console.error(
          'Axtolab AI Connector plugin not found. Is it installed and activated?'
        )
        process.exit(1)
      }

      if (!res.ok) {
        const body = await res.text()
        console.error(`Error from server (HTTP ${res.status}): ${body}`)
        process.exit(1)
      }

      const envelope = (await res.json()) as { success: boolean; data: DeviceCodeResponse }
      deviceCodeData = envelope.data
    } catch (err: unknown) {
      if (err instanceof Error) {
        console.error(`Failed to reach ${siteUrl}: ${err.message}`)
      } else {
        console.error(`Failed to reach ${siteUrl}`)
      }
      process.exit(1)
    }

    const { device_code: deviceCode, user_code: userCode } = deviceCodeData

    // -----------------------------------------------------------------------
    // Step 3 — Display the instruction box + machine-readable output
    // -----------------------------------------------------------------------
    const boxWidth = 51 // inner width (between │ chars)
    const line1 = `  Go to: ${siteUrl}/wp-admin/`
    const line2 = `  Navigate to: Axtolab AI Connector in the sidebar`
    const line3 = `  Enter code: ${userCode}`

    function padLine(text: string): string {
      return text.padEnd(boxWidth - 2) // -2 for the two │ chars
    }

    console.log('\n┌' + '─'.repeat(boxWidth - 2) + '┐')
    console.log('│' + padLine(line1) + '│')
    console.log('│' + padLine(line2) + '│')
    console.log('│' + padLine(line3) + '│')
    console.log('└' + '─'.repeat(boxWidth - 2) + '┘')

    // Machine-readable lines for Claude to parse
    console.log(`AUTHORIZATION_CODE=${userCode}`)
    console.log(`VERIFICATION_URL=${siteUrl}/wp-admin/admin.php?page=axtolab-ai-connector`)
    console.log('')
    console.log('Enter this code in your WordPress admin panel under Axtolab AI Connector → Connect Claude.')
    console.log('Waiting for authorization...')

    // -----------------------------------------------------------------------
    // Step 4 — Poll /wp-json/axtolab-ai-connector/v1/device/token every 5s
    // -----------------------------------------------------------------------
    const pollEndpoint = `${siteUrl}/wp-json/axtolab-ai-connector/v1/device/token?device_code=${encodeURIComponent(deviceCode)}`
    const pollInterval = 5_000 // 5 seconds
    const timeoutMs = 15 * 60 * 1_000 // 15 minutes
    const startTime = Date.now()

    let tokenData: DeviceTokenResponse | null = null

    while (Date.now() - startTime < timeoutMs) {
      await sleep(pollInterval)
      process.stdout.write('.')

      let pollRes: Response
      try {
        pollRes = await fetch(pollEndpoint, { method: 'GET' })
      } catch (err: unknown) {
        // Network error — keep polling
        continue
      }

      if (!pollRes.ok) {
        // Unexpected server error — keep polling rather than bailing
        continue
      }

      const body = (await pollRes.json()) as DeviceTokenResponse

      if (body.status === 'pending') {
        continue
      }

      tokenData = body
      break
    }

    console.log('') // newline after dots

    // -----------------------------------------------------------------------
    // Step 5 — Handle terminal states
    // -----------------------------------------------------------------------
    if (tokenData === null) {
      // Timed out on the client side
      console.error('Timed out waiting for authorization. Run setup again.')
      process.exit(1)
    }

    if (tokenData.status === 'expired') {
      console.error('Code expired. Run setup again.')
      process.exit(1)
    }

    if (tokenData.status !== 'approved') {
      console.error(`Unexpected status: ${String((tokenData as { status: string }).status)}`)
      process.exit(1)
    }

    // -----------------------------------------------------------------------
    // Step 6 — Save credentials + write ~/.claude/mcp.json
    // -----------------------------------------------------------------------
    const approved = tokenData as DeviceTokenApproved

    await saveCredentials(hostname, {
      siteUrl,
      wpPluginBaseUrl: approved.wp_plugin_base_url,
      username: approved.service_user,
      appPassword: approved.token,
      siteName: approved.site_name,
      connectedAt: new Date().toISOString(),
    })

    await writeMcpJson(hostname)

    console.log(
      `\u2713 Connected to ${approved.site_name}! Restart Claude to start using it.`
    )
    console.log(`MCP_CONFIG_WRITTEN=true`)
    console.log(`MCP_SITE=${hostname}`)
  } finally {
    if (rl) rl.close()
  }
}

// ---------------------------------------------------------------------------
// List connections
// ---------------------------------------------------------------------------

export async function listConnections(): Promise<void> {
  const sites = await listSites()

  if (sites.length === 0) {
    console.log('No connected sites. Generate a connection token from WordPress Admin -> AI Connector and add it to your desktop extension settings.')
    return
  }

  console.log('\nConnected sites:')
  for (const site of sites) {
    const date = site.connectedAt ? site.connectedAt.slice(0, 10) : 'unknown'
    const col1 = site.hostname.padEnd(30)
    const col2 = (site.siteName || '').padEnd(24)
    console.log(`  ${col1}  ${col2}  Connected ${date}`)
  }
  console.log('')
}

// ---------------------------------------------------------------------------
// Remove a connection
// ---------------------------------------------------------------------------

export async function removeConnection(hostname: string): Promise<void> {
  const creds = await loadCredentials(hostname)

  if (!creds) {
    console.log(
      `No connection found for ${hostname}. Check the desktop extension settings for connected sites.`
    )
    return
  }

  await removeCredentials(hostname)
  await removeFromMcpJson(hostname)

  console.log(
    `\u2713 Removed ${hostname}. Restart Claude for the change to take effect.`
  )
}

// ---------------------------------------------------------------------------
// Connect via token (no HTTP required)
// ---------------------------------------------------------------------------

export async function connectViaToken(tokenString: string): Promise<void> {
  const payload = decodeConnectionToken(tokenString)

  const hostname = hostnameFrom(payload.site_url)

  await saveCredentials(hostname, {
    siteUrl: payload.site_url,
    wpPluginBaseUrl: payload.base_url,
    username: payload.username,
    appPassword: payload.token,
    siteName: payload.site_name,
    connectedAt: new Date().toISOString(),
  })

  await writeMcpJson(hostname)

  console.log(
    `\u2713 Connected to ${payload.site_name}! Restart Claude to start using it.`
  )
  console.log(`MCP_CONFIG_WRITTEN=true`)
  console.log(`MCP_SITE=${hostname}`)
}
