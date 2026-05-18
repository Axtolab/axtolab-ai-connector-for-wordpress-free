import { describe, expect, it } from 'vitest'
import { PluginApiClient } from '../src/client/pluginApiClient.js'
import { Config } from '../src/config.js'
import { ConfirmationService } from '../src/services/confirmationService.js'
import { PolicyService } from '../src/services/policyService.js'
import { RateLimiter } from '../src/services/rateLimiter.js'
import { SessionImageStore } from '../src/services/sessionImageStore.js'
import { SiteManager, SiteServices } from '../src/services/siteManager.js'
import { registerTools } from '../src/tools/registerTools.js'

function makeConfig(): Config {
  return {
    wpPluginBaseUrl: 'https://example.com/wp-json/wp-mcp-gateway/v1',
    username: 'test',
    appPassword: 'pass',
    allowedContentTypes: [],
    allowedTaxonomies: [],
    allowedAuthors: [],
    allowedMediaTypes: [],
    allowedYoastPaths: [],
    rateLimitTokens: 60,
    rateLimitRefillRate: 1,
    httpTimeout: 30_000,
  }
}

function makeSiteServices(blocked: boolean): SiteServices {
  const config = makeConfig()
  return {
    config,
    client: new PluginApiClient(config),
    policy: new PolicyService(config),
    allowedTools: blocked ? [] : null,
    allowedAuthorIds: null,
    connectionCapabilityError: blocked
      ? {
          code: 'free_multisite_disabled',
          message: 'AI Connector Free is disabled on WordPress multisite.',
        }
      : null,
  }
}

function makeFakeServer() {
  const tools = new Map<string, { enabled: boolean; enable(): void; disable(): void }>()
  return {
    tools,
    server: {
      tool(name: string) {
        const registered = {
          enabled: true,
          enable() {
            registered.enabled = true
          },
          disable() {
            registered.enabled = false
          },
        }
        tools.set(name, registered)
        return registered
      },
    },
  }
}

describe('registerTools visibility', () => {
  it('fails closed for blocked Free multisite connections in local MCP tools/list state', () => {
    const siteManager = new SiteManager(
      new Map([['example.com', makeSiteServices(true)]]),
      'example.com'
    )
    const { server, tools } = makeFakeServer()

    registerTools({
      server,
      siteManager,
      confirmations: new ConfirmationService(300),
      rateLimiter: new RateLimiter(60, 1),
      sessionImageStore: new SessionImageStore(),
    })

    expect(tools.get('wp_list_sites')?.enabled).toBe(true)
    expect(tools.get('wp_switch_site')?.enabled).toBe(true)
    expect(tools.get('wp_find_media_file')?.enabled).toBe(true)

    expect(tools.get('wp_site_info')?.enabled).toBe(false)
    expect(tools.get('wp_getting_started')?.enabled).toBe(false)
    expect(tools.get('wp_create_draft')?.enabled).toBe(false)
    expect(tools.get('wp_upload_media_from_path')?.enabled).toBe(false)
    expect(tools.get('wp_generate_image')?.enabled).toBe(false)
  })

  it('registers wp_search_content alongside wp_find_content (parity with Royal MCP "Search")', () => {
    // Plan ref: programs/ai-connector-launch/royal-parity-phase-1.md item #1.
    // wp_search_content is a thin alias over wp_find_content surfacing
    // full-text search under the discoverable name competitors use. Both
    // tools should register and be enabled for unblocked connections.
    const siteManager = new SiteManager(
      new Map([['example.com', makeSiteServices(false)]]),
      'example.com'
    )
    const { server, tools } = makeFakeServer()

    registerTools({
      server,
      siteManager,
      confirmations: new ConfirmationService(300),
      rateLimiter: new RateLimiter(60, 1),
      sessionImageStore: new SessionImageStore(),
    })

    expect(tools.get('wp_find_content')?.enabled).toBe(true)
    expect(tools.get('wp_search_content')?.enabled).toBe(true)
  })

  it('registers permalink structure tools (Royal-parity Phase 1 #2)', () => {
    // Plan ref: programs/ai-connector-launch/royal-parity-phase-1.md item #2.
    // Both read + update tools should register; the update tool is gated
    // server-side by a three-layer check (admin toggle + manage_options +
    // input validation), enforced when the tool actually runs, not at
    // registration time.
    const siteManager = new SiteManager(
      new Map([['example.com', makeSiteServices(false)]]),
      'example.com'
    )
    const { server, tools } = makeFakeServer()

    registerTools({
      server,
      siteManager,
      confirmations: new ConfirmationService(300),
      rateLimiter: new RateLimiter(60, 1),
      sessionImageStore: new SessionImageStore(),
    })

    expect(tools.get('wp_get_permalink_structure')?.enabled).toBe(true)
    expect(tools.get('wp_update_permalink_structure')?.enabled).toBe(true)
  })

  it('registers plugin/theme inventory tools (Royal-parity Phase 1 #3)', () => {
    // Plan ref: programs/ai-connector-launch/royal-parity-phase-1.md item #3.
    // Read-only inventory tools — no install/activate.
    const siteManager = new SiteManager(
      new Map([['example.com', makeSiteServices(false)]]),
      'example.com'
    )
    const { server, tools } = makeFakeServer()

    registerTools({
      server,
      siteManager,
      confirmations: new ConfirmationService(300),
      rateLimiter: new RateLimiter(60, 1),
      sessionImageStore: new SessionImageStore(),
    })

    expect(tools.get('wp_list_plugins')?.enabled).toBe(true)
    expect(tools.get('wp_list_themes')?.enabled).toBe(true)
  })

  it('registers comments moderation surface (Royal-parity Phase 1 #4)', () => {
    // Plan ref: programs/ai-connector-launch/royal-parity-phase-1.md item #4.
    // Comments CRUD + moderate_comment + the new pending-list alias should
    // all register; capability already shipped, this commit improved
    // discoverability via the pending-list wrapper.
    const siteManager = new SiteManager(
      new Map([['example.com', makeSiteServices(false)]]),
      'example.com'
    )
    const { server, tools } = makeFakeServer()

    registerTools({
      server,
      siteManager,
      confirmations: new ConfirmationService(300),
      rateLimiter: new RateLimiter(60, 1),
      sessionImageStore: new SessionImageStore(),
    })

    expect(tools.get('wp_list_comments')?.enabled).toBe(true)
    expect(tools.get('wp_list_pending_comments')?.enabled).toBe(true)
    expect(tools.get('wp_moderate_comment')?.enabled).toBe(true)
  })

  it('registers term meta CRUD (Royal-parity Phase 1 #5)', () => {
    // Plan ref: programs/ai-connector-launch/royal-parity-phase-1.md item #5.
    const siteManager = new SiteManager(
      new Map([['example.com', makeSiteServices(false)]]),
      'example.com'
    )
    const { server, tools } = makeFakeServer()

    registerTools({
      server,
      siteManager,
      confirmations: new ConfirmationService(300),
      rateLimiter: new RateLimiter(60, 1),
      sessionImageStore: new SessionImageStore(),
    })

    expect(tools.get('wp_get_term_meta')?.enabled).toBe(true)
    expect(tools.get('wp_update_term_meta')?.enabled).toBe(true)
    expect(tools.get('wp_delete_term_meta')?.enabled).toBe(true)
  })

  it('registers Options API surface (Royal-parity Phase 1 #6)', () => {
    // Plan ref: programs/ai-connector-launch/royal-parity-phase-1.md item #6.
    // Three-gate write security tested at runtime via the boundary proofs;
    // here we just assert tool registration.
    const siteManager = new SiteManager(
      new Map([['example.com', makeSiteServices(false)]]),
      'example.com'
    )
    const { server, tools } = makeFakeServer()

    registerTools({
      server,
      siteManager,
      confirmations: new ConfirmationService(300),
      rateLimiter: new RateLimiter(60, 1),
      sessionImageStore: new SessionImageStore(),
    })

    expect(tools.get('wp_get_option')?.enabled).toBe(true)
    expect(tools.get('wp_update_option')?.enabled).toBe(true)
    expect(tools.get('wp_get_plugin_settings')?.enabled).toBe(true)
  })

  it('registers generic SEO meta tools (Royal-parity Phase 2 #8)', () => {
    // Plan ref: programs/ai-connector-launch/royal-parity-phase-1.md item #8.
    // Provider-neutral SEO read/write that auto-detects Yoast / Rank Math /
    // AIOSEO. Existing Yoast-specific tools also still register.
    const siteManager = new SiteManager(
      new Map([['example.com', makeSiteServices(false)]]),
      'example.com'
    )
    const { server, tools } = makeFakeServer()

    registerTools({
      server,
      siteManager,
      confirmations: new ConfirmationService(300),
      rateLimiter: new RateLimiter(60, 1),
      sessionImageStore: new SessionImageStore(),
    })

    expect(tools.get('wp_get_seo_meta')?.enabled).toBe(true)
    expect(tools.get('wp_update_seo_meta')?.enabled).toBe(true)
    // Legacy Yoast-specific tools still register for backward compat.
    expect(tools.get('wp_get_yoast_analysis')?.enabled).toBe(true)
    expect(tools.get('wp_update_yoast_metadata')?.enabled).toBe(true)
  })

  it('registers nav menu tools (Royal-parity Phase 2 #9)', () => {
    const siteManager = new SiteManager(
      new Map([['example.com', makeSiteServices(false)]]),
      'example.com'
    )
    const { server, tools } = makeFakeServer()

    registerTools({
      server,
      siteManager,
      confirmations: new ConfirmationService(300),
      rateLimiter: new RateLimiter(60, 1),
      sessionImageStore: new SessionImageStore(),
    })

    expect(tools.get('wp_list_menus')?.enabled).toBe(true)
    expect(tools.get('wp_get_menu')?.enabled).toBe(true)
    expect(tools.get('wp_create_menu_item')?.enabled).toBe(true)
    expect(tools.get('wp_update_menu_item')?.enabled).toBe(true)
    expect(tools.get('wp_delete_menu_item')?.enabled).toBe(true)
    expect(tools.get('wp_reorder_menu_items')?.enabled).toBe(true)
  })

  it('registers Theme Appearance tools (Royal-parity Phase 2 #10)', () => {
    const siteManager = new SiteManager(
      new Map([['example.com', makeSiteServices(false)]]),
      'example.com'
    )
    const { server, tools } = makeFakeServer()

    registerTools({
      server,
      siteManager,
      confirmations: new ConfirmationService(300),
      rateLimiter: new RateLimiter(60, 1),
      sessionImageStore: new SessionImageStore(),
    })

    expect(tools.get('wp_get_active_theme')?.enabled).toBe(true)
    expect(tools.get('wp_get_theme_mods')?.enabled).toBe(true)
    expect(tools.get('wp_update_theme_mod')?.enabled).toBe(true)
    expect(tools.get('wp_get_custom_css')?.enabled).toBe(true)
    expect(tools.get('wp_update_custom_css')?.enabled).toBe(true)
  })

  it('registers gap-fill tools (term update/delete, user reads, audit log)', () => {
    const siteManager = new SiteManager(
      new Map([['example.com', makeSiteServices(false)]]),
      'example.com'
    )
    const { server, tools } = makeFakeServer()

    registerTools({
      server,
      siteManager,
      confirmations: new ConfirmationService(300),
      rateLimiter: new RateLimiter(60, 1),
      sessionImageStore: new SessionImageStore(),
    })

    expect(tools.get('wp_update_term')?.enabled).toBe(true)
    expect(tools.get('wp_delete_term')?.enabled).toBe(true)
    expect(tools.get('wp_list_users')?.enabled).toBe(true)
    expect(tools.get('wp_get_user')?.enabled).toBe(true)
    expect(tools.get('wp_get_audit_log')?.enabled).toBe(true)
  })

  it('registers wp_rollback_session (Phase 5 Step 8)', () => {
    const siteManager = new SiteManager(
      new Map([['example.com', makeSiteServices(false)]]),
      'example.com'
    )
    const { server, tools } = makeFakeServer()

    registerTools({
      server,
      siteManager,
      confirmations: new ConfirmationService(300),
      rateLimiter: new RateLimiter(60, 1),
      sessionImageStore: new SessionImageStore(),
    })

    expect(tools.get('wp_rollback_session')?.enabled).toBe(true)
  })

  it('registers wp_redo_change (Phase 5 Step 7)', () => {
    const siteManager = new SiteManager(
      new Map([['example.com', makeSiteServices(false)]]),
      'example.com'
    )
    const { server, tools } = makeFakeServer()

    registerTools({
      server,
      siteManager,
      confirmations: new ConfirmationService(300),
      rateLimiter: new RateLimiter(60, 1),
      sessionImageStore: new SessionImageStore(),
    })

    expect(tools.get('wp_redo_change')?.enabled).toBe(true)
  })

  it('registers wp_rollback_change (Phase 5 Step 5)', () => {
    // Roll Back / Undo flagship: two-step (issue token → execute).
    // Should always register and be enabled for unblocked
    // connections; capability filter on the MCP transport gates it
    // via the trash_restore group.
    const siteManager = new SiteManager(
      new Map([['example.com', makeSiteServices(false)]]),
      'example.com'
    )
    const { server, tools } = makeFakeServer()

    registerTools({
      server,
      siteManager,
      confirmations: new ConfirmationService(300),
      rateLimiter: new RateLimiter(60, 1),
      sessionImageStore: new SessionImageStore(),
    })

    expect(tools.get('wp_rollback_change')?.enabled).toBe(true)
  })

  it('registers changelog read tools (Phase 5 Step 3+4)', () => {
    // Phase 5 Roll Back / Undo: wp_get_changelog lists recorded
    // changes with filters; wp_get_change returns one row including
    // before/after snapshots. Both available for any unblocked
    // connection so AI agents can review what's happened.
    const siteManager = new SiteManager(
      new Map([['example.com', makeSiteServices(false)]]),
      'example.com'
    )
    const { server, tools } = makeFakeServer()

    registerTools({
      server,
      siteManager,
      confirmations: new ConfirmationService(300),
      rateLimiter: new RateLimiter(60, 1),
      sessionImageStore: new SessionImageStore(),
    })

    expect(tools.get('wp_get_changelog')?.enabled).toBe(true)
    expect(tools.get('wp_get_change')?.enabled).toBe(true)
  })

  it('registers wp_get_my_capabilities (connection introspection)', () => {
    // Reads the active connection's capability groups + matching preset
    // + resolved tool list. Available regardless of the connection's
    // capability filter (always enabled when reachable).
    const siteManager = new SiteManager(
      new Map([['example.com', makeSiteServices(false)]]),
      'example.com'
    )
    const { server, tools } = makeFakeServer()

    registerTools({
      server,
      siteManager,
      confirmations: new ConfirmationService(300),
      rateLimiter: new RateLimiter(60, 1),
      sessionImageStore: new SessionImageStore(),
    })

    expect(tools.get('wp_get_my_capabilities')?.enabled).toBe(true)
  })

  it('registers WP Abilities API bridge tools (StifLi-parity Phase 4a)', () => {
    const siteManager = new SiteManager(
      new Map([['example.com', makeSiteServices(false)]]),
      'example.com'
    )
    const { server, tools } = makeFakeServer()

    registerTools({
      server,
      siteManager,
      confirmations: new ConfirmationService(300),
      rateLimiter: new RateLimiter(60, 1),
      sessionImageStore: new SessionImageStore(),
    })

    expect(tools.get('wp_list_abilities')?.enabled).toBe(true)
    expect(tools.get('wp_invoke_ability')?.enabled).toBe(true)
  })

})
