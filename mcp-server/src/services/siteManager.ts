import { Config } from '../config.js'
import { PluginApiClient } from '../client/pluginApiClient.js'
import { PolicyService } from './policyService.js'
import type { ToolConsentPolicyMap } from './toolConsentPolicy.js'
import { ToolError } from '../utils/errors.js'

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

/**
 * Services bound to a single WordPress site.
 */
export interface SiteServices {
  config: Config
  client: PluginApiClient
  policy: PolicyService
  toolConsentPolicy: ToolConsentPolicyMap
  allowedTools: string[] | null      // null = all allowed (older plugin or fetch failed)
  allowedAuthorIds: number[] | null  // null = any author allowed
  connectionCapabilityError?: { code: string; message: string } | null
}

// ---------------------------------------------------------------------------
// SiteManager
// ---------------------------------------------------------------------------

/**
 * Manages multiple WordPress site connections and tracks which one is active.
 *
 * Tools access the active site's services via `getCurrent()`.
 * The active site can be changed at runtime via `switchTo()`.
 */
export class SiteManager {
  private activeSiteHostname: string

  constructor(
    private readonly sites: Map<string, SiteServices>,
    initialHostname: string
  ) {
    if (!sites.has(initialHostname)) {
      throw new Error(
        `Initial site "${initialHostname}" not found in loaded sites: ${Array.from(sites.keys()).join(', ')}`
      )
    }
    this.activeSiteHostname = initialHostname
  }

  /**
   * Get the active site's services (config, client, policy).
   */
  getCurrent(): SiteServices {
    return this.sites.get(this.activeSiteHostname)!
  }

  /**
   * Get the hostname of the currently active site.
   */
  getCurrentHostname(): string {
    return this.activeSiteHostname
  }

  /**
   * Switch the active site to a different connected site.
   * Throws ToolError if the hostname is not in the loaded sites.
   */
  switchTo(hostname: string): void {
    if (!this.sites.has(hostname)) {
      const available = Array.from(this.sites.keys()).join(', ')
      throw new ToolError(
        'SITE_NOT_FOUND',
        `Site "${hostname}" is not connected. Available sites: ${available}`
      )
    }
    this.activeSiteHostname = hostname
  }

  /**
   * List all connected sites with an isActive flag.
   */
  listSites(): Array<{ hostname: string; siteName: string; isActive: boolean; blocked?: { code: string; message: string } }> {
    return Array.from(this.sites.entries()).map(([hostname, services]) => ({
      hostname,
      siteName: services.config.siteName || hostname,
      isActive: hostname === this.activeSiteHostname,
      ...(services.connectionCapabilityError ? { blocked: services.connectionCapabilityError } : {}),
    }))
  }

  /**
   * True if more than one site is loaded.
   */
  isMultiSite(): boolean {
    return this.sites.size > 1
  }

  /**
   * Hot-add a new site at runtime (e.g. from a connection token).
   * If the site already exists, its services are replaced.
   */
  addSite(hostname: string, services: SiteServices): void {
    this.sites.set(hostname, services)
  }

  /**
   * Check if a site is already loaded.
   */
  hasSite(hostname: string): boolean {
    return this.sites.has(hostname)
  }
}
