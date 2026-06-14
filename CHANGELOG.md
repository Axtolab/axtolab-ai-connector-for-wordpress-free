# Changelog

All notable changes to this project are documented in this file.

## [1.0.2] — 2026-06-14

### Added
- **WooCommerce MCP tools in the free connector.** When WooCommerce is active, AI clients can list products and orders, inspect product/order details, update product prices, bulk-adjust prices, and create coupons.
- **WooCommerce guardrails and Roll Back support.** Product price and coupon writes keep capability checks, configurable guardrails, audit logging, and one-click Roll Back entries.

### Changed
- Kept the WordPress minimum at 6.2 while retaining optional WordPress 6.8+/6.9+ integrations behind compatibility-safe dispatch.

## [1.0.0] — 2026-05-17

First public release.

### Added
- **Roll Back / Undo on every write.** Every AI-driven create, update, publish, trash, or restore captures a before/after snapshot. Revert any change with one click from the Logs & Roll Back admin page.
- **MCP tool surface** across content authoring, media management, taxonomy, authors, Yoast SEO, stock photos, image generation, upload portal, connection introspection, and Roll Back.
- **Two authentication methods:** WordPress Application Passwords (HTTP Basic, primary path for Desktop AI clients) and OAuth 2.1 with PKCE S256 + Dynamic Client Registration (RFC 7591, for web clients like ChatGPT and Claude Web). OAuth issues standard `Authorization: Bearer ...` tokens for the MCP-over-HTTP transport.
- **Capability-group-driven tool filtering** on the MCP transport — operators choose what AI clients on each connection are allowed to do (read, create_edit, publish, trash_restore, media_manage, taxonomy, authors, seo, image, upload_portal).
- **Provider-neutral SEO tools** that auto-detect Yoast or Rank Math.
- **Confirmation-token flow** required for destructive operations (publish, trash, restore).
- **Per-connection privilege model:** every MCP connection authenticates as a WordPress user via an Application Password the admin created in their own WP profile (or a dedicated WP user they set up). The connection's capability set determines which AI tools can be called; the user's WP role determines which objects they can act on. Both layers must allow an action for it to succeed. The plugin never creates WordPress users at any step.
- **Bundled `.mcpb` Claude Desktop installer** available as a passive download from the AI Connector setup page.
- **Health and ping endpoints** at `/wp-json/axtolab-ai-connector/v1/ping` and `/health-check` for connection status and plugin version.
- **WordPress 6.9 Abilities API bridge.**

### Security
- All third-party services disclosed in the plugin readme's External Services section.
- API keys for image providers stored encrypted (AES-256-CBC with WordPress security salts), decrypted only at the time of the API call.
- Per-IP rate limiting on authentication endpoints.
- Confirmation tokens are single-use and time-limited.
