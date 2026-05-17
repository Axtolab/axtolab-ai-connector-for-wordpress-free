import { loadCredentials, listSites } from './tokenStore.js'
import { isConnectionToken, decodeConnectionToken } from './tokenDecoder.js'

// ---------------------------------------------------------------------------
// Config shape
// ---------------------------------------------------------------------------

export interface Config {
  // WordPress connection
  wpPluginBaseUrl: string
  username: string
  appPassword: string

  // Optional site metadata (populated when loaded from token store)
  siteName?: string

  // Allowlists (empty array = allow all)
  allowedContentTypes: string[]
  allowedTaxonomies: string[]
  allowedAuthors: string[]
  allowedMediaTypes: string[]
  allowedYoastPaths: string[]

  // Rate limiting
  rateLimitTokens: number
  rateLimitRefillRate: number // tokens per second

  // HTTP timeouts (ms)
  httpTimeout: number
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function splitList(value: string | undefined): string[] {
  if (!value) return []
  return value
    .split(',')
    .map((s) => s.trim())
    .filter(Boolean)
}

function getNumber(
  value: string | undefined,
  defaultValue: number
): number {
  if (!value) return defaultValue
  const n = Number(value)
  return Number.isFinite(n) ? n : defaultValue
}

// ---------------------------------------------------------------------------
// loadConfig
// ---------------------------------------------------------------------------

/**
 * Load configuration for the MCP server.
 *
 * Priority:
 *  1. Token-store mode — when `WP_MCP_SITE` env var is set.
 *     Credentials are read from ~/.axtolab-ai-connector/credentials.json.
 *     Throws if no credentials are stored for that site.
 *
 *  2. Legacy env-var mode — `WP_PLUGIN_BASE_URL` + `WP_USERNAME` + `WP_APP_PASSWORD`.
 *     Used for manual / CI setups, fully backwards compatible.
 */
export type ServerConfig = Config

export async function loadConfig(): Promise<Config> {
  const env = process.env

  // -------------------------------------------------------------------------
  // Mode 1: Token store (WP_MCP_SITE)
  // -------------------------------------------------------------------------
  if (env['WP_MCP_SITE']) {
    const site = env['WP_MCP_SITE'].trim()
    const creds = await loadCredentials(site)

    if (!creds) {
      throw new Error(
        `No stored credentials for ${site}. Generate a new connection token from WordPress Admin -> AI Connector and add it to your desktop extension settings.`
      )
    }

    return {
      wpPluginBaseUrl: creds.wpPluginBaseUrl,
      username: creds.username,
      appPassword: creds.appPassword,
      siteName: creds.siteName,

      allowedContentTypes: splitList(env['WP_ALLOWED_CONTENT_TYPES']),
      allowedTaxonomies: splitList(env['WP_ALLOWED_TAXONOMIES']),
      allowedAuthors: splitList(env['WP_ALLOWED_AUTHORS']),
      allowedMediaTypes: splitList(env['WP_ALLOWED_MEDIA_TYPES']),
      allowedYoastPaths: splitList(env['WP_ALLOWED_YOAST_PATHS']),

      rateLimitTokens: getNumber(env['RATE_LIMIT_TOKENS'], 60),
      rateLimitRefillRate: getNumber(env['RATE_LIMIT_REFILL_RATE'], 1),

      httpTimeout: getNumber(env['HTTP_TIMEOUT'], 30_000),
    }
  }

  // -------------------------------------------------------------------------
  // Mode 2: Legacy env-var mode
  // -------------------------------------------------------------------------
  const wpPluginBaseUrl = env['WP_PLUGIN_BASE_URL']
  const username = env['WP_USERNAME']
  const appPassword = env['WP_APP_PASSWORD']

  if (!wpPluginBaseUrl || !username || !appPassword) {
    throw new Error(
      'Missing required environment variables. ' +
        'Set WP_MCP_SITE (token store mode) or ' +
        'WP_PLUGIN_BASE_URL + WP_USERNAME + WP_APP_PASSWORD (legacy mode).'
    )
  }

  return {
    wpPluginBaseUrl,
    username,
    appPassword,

    allowedContentTypes: splitList(env['WP_ALLOWED_CONTENT_TYPES']),
    allowedTaxonomies: splitList(env['WP_ALLOWED_TAXONOMIES']),
    allowedAuthors: splitList(env['WP_ALLOWED_AUTHORS']),
    allowedMediaTypes: splitList(env['WP_ALLOWED_MEDIA_TYPES']),
    allowedYoastPaths: splitList(env['WP_ALLOWED_YOAST_PATHS']),

    rateLimitTokens: getNumber(env['RATE_LIMIT_TOKENS'], 60),
    rateLimitRefillRate: getNumber(env['RATE_LIMIT_REFILL_RATE'], 1),

    httpTimeout: getNumber(env['HTTP_TIMEOUT'], 30_000),
  }
}

// ---------------------------------------------------------------------------
// hostnameFromUrl
// ---------------------------------------------------------------------------

/**
 * Extract the hostname from a WordPress plugin base URL.
 */
export function hostnameFromUrl(wpPluginBaseUrl: string): string {
  try {
    return new URL(wpPluginBaseUrl).hostname
  } catch {
    // Fallback: rough extraction
    const match = wpPluginBaseUrl.match(/\/\/([^/]+)/)
    return match ? match[1] : wpPluginBaseUrl
  }
}

// ---------------------------------------------------------------------------
// loadAllConfigs — multi-site support
// ---------------------------------------------------------------------------

/**
 * Load configs for ALL connected sites.
 *
 * Priority:
 *  1. If WP_MCP_SITE is set → single-site mode (backward compat).
 *  2. Otherwise → load all sites from token store.
 *  3. If token store is empty → fall back to legacy env-var mode.
 *
 * Returns a Map keyed by hostname.
 */
export async function loadAllConfigs(): Promise<Map<string, Config>> {
  const env = process.env

  // -------------------------------------------------------------------------
  // Mode 0: MCPB token mode (WP_MCP_TOKENS)
  // -------------------------------------------------------------------------
  // Tokens come from the MCPB extension settings (user_config.site_tokens).
  // Claude Desktop passes them via env var, either comma-separated or as
  // repeated values. Each is a wmcp1_* self-contained credential token.
  if (env['WP_MCP_TOKENS']) {
    const raw = env['WP_MCP_TOKENS']
    const tokenStrings = raw.split(',').map(s => s.trim()).filter(Boolean)
    const map = new Map<string, Config>()

    for (const tokenString of tokenStrings) {
      if (!isConnectionToken(tokenString)) {
        console.error('Skipping invalid token (not wmcp1_ format)')
        continue
      }
      try {
        const payload = decodeConnectionToken(tokenString)
        const hostname = new URL(payload.site_url).hostname

        map.set(hostname, {
          wpPluginBaseUrl: payload.base_url,
          username: payload.username,
          appPassword: payload.token,
          siteName: payload.site_name,

          allowedContentTypes: splitList(env['WP_ALLOWED_CONTENT_TYPES']),
          allowedTaxonomies: splitList(env['WP_ALLOWED_TAXONOMIES']),
          allowedAuthors: splitList(env['WP_ALLOWED_AUTHORS']),
          allowedMediaTypes: splitList(env['WP_ALLOWED_MEDIA_TYPES']),
          allowedYoastPaths: splitList(env['WP_ALLOWED_YOAST_PATHS']),

          rateLimitTokens: getNumber(env['RATE_LIMIT_TOKENS'], 60),
          rateLimitRefillRate: getNumber(env['RATE_LIMIT_REFILL_RATE'], 1),
          httpTimeout: getNumber(env['HTTP_TIMEOUT'], 30_000),
        })
      } catch (err) {
        console.error(`Failed to decode token: ${(err as Error).message}`)
      }
    }

    if (map.size === 0) {
      throw new Error(
        'WP_MCP_TOKENS is set but no valid tokens could be decoded. ' +
        'Check your extension settings in Claude Desktop.'
      )
    }

    return map
  }

  // -------------------------------------------------------------------------
  // Mode 1: Single-site (WP_MCP_SITE set) — backward compatible
  // -------------------------------------------------------------------------
  if (env['WP_MCP_SITE']) {
    const config = await loadConfig()
    const hostname = env['WP_MCP_SITE'].trim()
    return new Map([[hostname, config]])
  }

  // -------------------------------------------------------------------------
  // Mode 2: Multi-site — load all from token store
  // -------------------------------------------------------------------------
  const allSites = await listSites()

  if (allSites.length > 0) {
    const map = new Map<string, Config>()

    for (const site of allSites) {
      map.set(site.hostname, {
        wpPluginBaseUrl: site.wpPluginBaseUrl,
        username: site.username,
        appPassword: site.appPassword,
        siteName: site.siteName,

        // Global allowlists from env (shared across all sites)
        allowedContentTypes: splitList(env['WP_ALLOWED_CONTENT_TYPES']),
        allowedTaxonomies: splitList(env['WP_ALLOWED_TAXONOMIES']),
        allowedAuthors: splitList(env['WP_ALLOWED_AUTHORS']),
        allowedMediaTypes: splitList(env['WP_ALLOWED_MEDIA_TYPES']),
        allowedYoastPaths: splitList(env['WP_ALLOWED_YOAST_PATHS']),

        rateLimitTokens: getNumber(env['RATE_LIMIT_TOKENS'], 60),
        rateLimitRefillRate: getNumber(env['RATE_LIMIT_REFILL_RATE'], 1),

        httpTimeout: getNumber(env['HTTP_TIMEOUT'], 30_000),
      })
    }

    return map
  }

  // -------------------------------------------------------------------------
  // Mode 3: Legacy env-var fallback (single site)
  // -------------------------------------------------------------------------
  const config = await loadConfig()
  const hostname = hostnameFromUrl(config.wpPluginBaseUrl)
  return new Map([[hostname, config]])
}
