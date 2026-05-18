import { describe, expect, it } from 'vitest'
import { SiteManager, SiteServices } from '../src/services/siteManager.js'
import { Config } from '../src/config.js'
import { PluginApiClient } from '../src/client/pluginApiClient.js'
import { PolicyService } from '../src/services/policyService.js'

function makeConfig(overrides: Partial<Config> = {}): Config {
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
    ...overrides,
  }
}

function makeSiteServices(allowedTools: string[] | null = null, connectionCapabilityError: { code: string; message: string } | null = null): SiteServices {
  const config = makeConfig()
  return {
    config,
    client: new PluginApiClient(config),
    policy: new PolicyService(config),
    allowedTools,
    allowedAuthorIds: null,
    connectionCapabilityError,
  }
}

describe('SiteManager', () => {
  it('stores and returns allowedTools', () => {
    const services = makeSiteServices(['wp_site_info', 'wp_find_content'])
    const map = new Map([['example.com', services]])
    const manager = new SiteManager(map, 'example.com')

    const current = manager.getCurrent()
    expect(current.allowedTools).toEqual(['wp_site_info', 'wp_find_content'])
  })

  it('allows null allowedTools for unrestricted access', () => {
    const services = makeSiteServices(null)
    const map = new Map([['example.com', services]])
    const manager = new SiteManager(map, 'example.com')

    expect(manager.getCurrent().allowedTools).toBeNull()
  })

  it('switches site and returns correct allowedTools', () => {
    const site1 = makeSiteServices(['wp_site_info'])
    const site2 = makeSiteServices(['wp_site_info', 'wp_create_draft', 'wp_publish_content'])

    const map = new Map([
      ['site1.com', site1],
      ['site2.com', site2],
    ])
    const manager = new SiteManager(map, 'site1.com')

    expect(manager.getCurrent().allowedTools).toEqual(['wp_site_info'])

    manager.switchTo('site2.com')
    expect(manager.getCurrent().allowedTools).toEqual([
      'wp_site_info',
      'wp_create_draft',
      'wp_publish_content',
    ])
  })


  it('keeps Free multisite capability failures as blocked site state', () => {
    const services = makeSiteServices([], {
      code: 'free_multisite_disabled',
      message: 'AI Connector Free is disabled on WordPress multisite.',
    })
    const map = new Map([['example.com', services]])
    const manager = new SiteManager(map, 'example.com')

    expect(manager.getCurrent().connectionCapabilityError).toEqual({
      code: 'free_multisite_disabled',
      message: 'AI Connector Free is disabled on WordPress multisite.',
    })
    expect(manager.listSites()[0]?.blocked?.code).toBe('free_multisite_disabled')
  })

  it('hot-adds a site with allowedTools', () => {
    const site1 = makeSiteServices(null)
    const map = new Map([['site1.com', site1]])
    const manager = new SiteManager(map, 'site1.com')

    const newSite = makeSiteServices(['wp_site_info', 'wp_get_content'])
    manager.addSite('new.com', newSite)

    expect(manager.hasSite('new.com')).toBe(true)
    manager.switchTo('new.com')
    expect(manager.getCurrent().allowedTools).toEqual(['wp_site_info', 'wp_get_content'])
  })
})

describe('capability enforcement logic', () => {
  // These tests verify the logic that would run in runToolWithLimit
  const BLOCKED_SITE_AVAILABLE_TOOLS = new Set([
    'wp_connect_site', 'wp_list_sites', 'wp_switch_site', 'wp_find_media_file',
  ])

  const CAPABILITY_CHECK_BYPASS_TOOLS = new Set([
    ...BLOCKED_SITE_AVAILABLE_TOOLS, 'wp_upload_media_from_path',
  ])

  function isToolAllowed(toolName: string, allowedTools: string[] | null, blocked = false): boolean {
    if (blocked && !BLOCKED_SITE_AVAILABLE_TOOLS.has(toolName)) return false
    if (CAPABILITY_CHECK_BYPASS_TOOLS.has(toolName)) return true
    if (allowedTools === null) return true
    return allowedTools.includes(toolName)
  }

  it('allows all tools when allowedTools is null', () => {
    expect(isToolAllowed('wp_create_draft', null)).toBe(true)
    expect(isToolAllowed('wp_trash_content', null)).toBe(true)
    expect(isToolAllowed('wp_publish_content', null)).toBe(true)
  })

  it('allows tools in the allowedTools list', () => {
    const allowed = ['wp_site_info', 'wp_find_content', 'wp_get_content']
    expect(isToolAllowed('wp_site_info', allowed)).toBe(true)
    expect(isToolAllowed('wp_find_content', allowed)).toBe(true)
    expect(isToolAllowed('wp_get_content', allowed)).toBe(true)
  })

  it('denies tools not in the allowedTools list', () => {
    const allowed = ['wp_site_info', 'wp_find_content']
    expect(isToolAllowed('wp_create_draft', allowed)).toBe(false)
    expect(isToolAllowed('wp_publish_content', allowed)).toBe(false)
    expect(isToolAllowed('wp_trash_content', allowed)).toBe(false)
  })

  it('allows local/helper tools regardless of allowedTools during normal operation', () => {
    const restrictedList = ['wp_site_info']
    expect(isToolAllowed('wp_connect_site', restrictedList)).toBe(true)
    expect(isToolAllowed('wp_list_sites', restrictedList)).toBe(true)
    expect(isToolAllowed('wp_switch_site', restrictedList)).toBe(true)
    expect(isToolAllowed('wp_find_media_file', restrictedList)).toBe(true)
    expect(isToolAllowed('wp_upload_media_from_path', restrictedList)).toBe(true)
  })

  it('fails closed on Free multisite capability errors except safe local management tools', () => {
    expect(isToolAllowed('wp_list_sites', [], true)).toBe(true)
    expect(isToolAllowed('wp_switch_site', [], true)).toBe(true)
    expect(isToolAllowed('wp_find_media_file', [], true)).toBe(true)
    expect(isToolAllowed('wp_site_info', [], true)).toBe(false)
    expect(isToolAllowed('wp_create_draft', [], true)).toBe(false)
    expect(isToolAllowed('wp_upload_media_from_path', [], true)).toBe(false)
  })

  it('allows blocked-site-safe local tools even with empty allowedTools', () => {
    const emptyList: string[] = []
    expect(isToolAllowed('wp_connect_site', emptyList)).toBe(true)
    expect(isToolAllowed('wp_list_sites', emptyList)).toBe(true)
  })

  it('denies non-local tools with empty allowedTools list', () => {
    const emptyList: string[] = []
    expect(isToolAllowed('wp_site_info', emptyList)).toBe(false)
    expect(isToolAllowed('wp_create_draft', emptyList)).toBe(false)
  })

  it('read-only preset allows only read tools', () => {
    // Simulates what the server would get from a "read_only" preset
    const readOnlyTools = [
      'wp_getting_started', 'wp_site_info', 'wp_list_content_types',
      'wp_find_content', 'wp_get_content', 'wp_list_revisions',
      'wp_list_authors', 'wp_list_terms', 'wp_search_media',
      'wp_get_media', 'wp_get_yoast_analysis', 'wp_get_yoast_head_preview',
      'wp_get_preview_link',
    ]

    // Read tools should be allowed
    expect(isToolAllowed('wp_site_info', readOnlyTools)).toBe(true)
    expect(isToolAllowed('wp_find_content', readOnlyTools)).toBe(true)
    expect(isToolAllowed('wp_search_media', readOnlyTools)).toBe(true)

    // Write tools should be denied
    expect(isToolAllowed('wp_create_draft', readOnlyTools)).toBe(false)
    expect(isToolAllowed('wp_update_content', readOnlyTools)).toBe(false)
    expect(isToolAllowed('wp_publish_content', readOnlyTools)).toBe(false)
    expect(isToolAllowed('wp_trash_content', readOnlyTools)).toBe(false)
    expect(isToolAllowed('wp_upload_media_from_url', readOnlyTools)).toBe(false)
  })
})
