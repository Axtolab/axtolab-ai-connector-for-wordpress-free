import { loadAllConfigs } from './config.js'

// ---------------------------------------------------------------------------
// Main MCP server entry point
// ---------------------------------------------------------------------------

async function main(): Promise<void> {
  const configMap = await loadAllConfigs()

  // Lazy-import heavy MCP SDK dependencies so server boot stays fast
  const { McpServer } = await import('@modelcontextprotocol/sdk/server/mcp.js')
  const { StdioServerTransport } = await import(
    '@modelcontextprotocol/sdk/server/stdio.js'
  )

  // Dynamically import service and tool modules to keep top-level light
  const { PluginApiClient } = await import('./client/pluginApiClient.js')
  const { ToolError } = await import('./utils/errors.js')
  const { PolicyService } = await import('./services/policyService.js')
  const { ToolConsentPolicy } = await import('./services/toolConsentPolicy.js')
  const { SiteManager } = await import('./services/siteManager.js')
  const { ConfirmationService } = await import(
    './services/confirmationService.js'
  )
  const { RateLimiter } = await import('./services/rateLimiter.js')
  const { SessionImageStore } = await import('./services/sessionImageStore.js')
  const { registerTools } = await import('./tools/registerTools.js')
  const { registerPrompts } = await import('./prompts/registerPrompts.js')

  // Build services for each connected site
  const siteServicesMap = new Map<
    string,
    { config: import('./config.js').Config; client: InstanceType<typeof PluginApiClient>; policy: InstanceType<typeof PolicyService>; toolConsentPolicy: import('./services/toolConsentPolicy.js').ToolConsentPolicyMap; allowedTools: string[] | null; allowedAuthorIds: number[] | null; connectionCapabilityError: { code: string; message: string } | null }
  >()

  for (const [hostname, config] of configMap) {
    const client = new PluginApiClient(config)
    const policy = new PolicyService(config)

    let allowedTools: string[] | null = null
    let allowedAuthorIds: number[] | null = null
    let toolConsentPolicy = ToolConsentPolicy.defaults()
    let connectionCapabilityError: { code: string; message: string } | null = null
    try {
      const capsResponse = await client.getConnectionCapabilities()
      allowedTools = capsResponse.allowed_tools
      allowedAuthorIds = capsResponse.allowed_author_ids ?? null
      toolConsentPolicy = ToolConsentPolicy.normalize(capsResponse.tool_consent_policy)
    } catch (error) {
      if (error instanceof ToolError && error.code === 'free_multisite_disabled') {
        // Free multisite connections are an intentional fail-closed state.
        // Do not fall back to the legacy "no restrictions" behavior, because
        // that would over-advertise local MCP tools for a blocked site.
        allowedTools = []
        allowedAuthorIds = null
        connectionCapabilityError = { code: error.code, message: error.message }
      } else {
        // Older plugin versions don't have this endpoint — treat as no restriction
        allowedTools = null
        allowedAuthorIds = null
      }
    }

    siteServicesMap.set(hostname, {
      config,
      client,
      policy,
      toolConsentPolicy,
      allowedTools,
      allowedAuthorIds,
      connectionCapabilityError,
    })
  }

  // Determine initial active site
  const initialSite = process.env['WP_MCP_SITE']?.trim() || Array.from(configMap.keys())[0]!
  const siteManager = new SiteManager(siteServicesMap, initialSite)

  // Build server description with site indicator
  const activeSiteName = siteManager.getCurrent().config.siteName || initialSite
  const siteCount = configMap.size
  const siteIndicator =
    siteCount > 1
      ? ` Connected to ${siteCount} sites (active: ${activeSiteName}). Use wp_switch_site to change.`
      : ''

  const server = new McpServer({
    name: 'axtolab-ai-connector',
    version: '1.0.2',
    description:
      'Axtolab AI Connector — proxies Claude tool calls to a WordPress site via the Axtolab AI Connector plugin.' +
      siteIndicator +
      ' Always call wp_getting_started first. Follow the IDEATE → DRAFT → PACKAGE workflow. ' +
      'Destructive actions (publish, trash, delete, restore) follow the site consent policy.',
  })

  // Global services (not per-site)
  const firstConfig = configMap.get(initialSite)!
  const confirmationService = new ConfirmationService(300)
  const rateLimiter = new RateLimiter(
    firstConfig.rateLimitTokens,
    firstConfig.rateLimitRefillRate
  )
  const sessionImageStore = new SessionImageStore()

  const context = {
    server,
    siteManager,
    confirmations: confirmationService,
    rateLimiter,
    sessionImageStore,
  }

  registerTools(context)
  registerPrompts(server)

  const transport = new StdioServerTransport()
  await server.connect(transport)
}

// ---------------------------------------------------------------------------
// Entry — start the MCP server (stdio transport). The server is intended to
// be launched by an MCP-compatible client (e.g. Claude Desktop loading the
// .mcpb extension); credentials reach the runtime via environment variables
// configured by the host client. Sites can also be added at runtime via the
// `wp_connect_site` MCP tool, which decodes a connection token from the
// WordPress plugin admin.
// ---------------------------------------------------------------------------

main()
