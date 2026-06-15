# Axtolab AI Connector for WordPress — Plugin

Let AI agents safely write, edit, and manage your WordPress site. This plugin provides the WordPress-side MCP Gateway for Claude, ChatGPT, and other MCP-compatible AI clients to manage content, media, and SEO through a secure REST API.

## Requirements

- WordPress 6.2+
- PHP 7.4+
- HTTPS recommended (required for OAuth)

## Installation

1. Download `axtolab-ai-connector-1.0.3.zip`
2. In WordPress admin: Plugins → Add New → Upload Plugin → select the zip
3. Activate the plugin
4. Connect your AI client (see Authentication below)

## Features

### Content Management
- Create, edit, publish, and schedule posts and pages
- Clone existing content as new drafts
- Revision history with restore capability
- Signed shareable preview links

### Media Library
- Upload from URL, local file path, or drag-and-drop portal
- Search and browse existing media
- Set featured images
- Insert, replace, and remove inline images with block-aware placement

### AI Image Generation
- Generate images with Google Imagen or OpenAI (API keys configured in Settings → AI Connector)
- Search and import free stock photos from Unsplash and Pexels with automatic attribution
- Generated images auto-cleanup after 24h if not confirmed

### Upload Portal
- Temporary drag-and-drop upload page — works for any MCP client
- Token-secured, time-limited (15 minutes), no WordPress login required
- SVG sanitization strips scripts and event handlers
- CSP headers and nonce validation

### Yoast SEO Integration
- Read SEO and readability analysis scores
- Update focus keyphrase, SEO title, meta description
- Preview rendered head/meta tags

### Authors & Taxonomies
- Assign authors from an allowlist
- Create and assign taxonomy terms (categories, tags, custom taxonomies)

### WooCommerce
- List products and orders when WooCommerce is active
- Update single product prices and bulk-adjust prices with configurable guardrails
- Create guarded coupons with Roll Back capture

## Authentication

The plugin supports two authentication methods:

### 1. Application Passwords (Default)
WordPress built-in Application Passwords via HTTP Basic Auth. Admins create the Application Password under their own (or a dedicated) WordPress user profile, paste it into the "+ Add new connection" wizard, and the wizard returns a `wmcp1_...` connection token bundling the same credentials. The token is consumed by the `wp_connect_site` MCP tool inside Claude Desktop's `.mcpb` extension. The plugin does not create WordPress users.

### 2. OAuth 2.1
For web-based clients (ChatGPT, Claude Web). Supports:
- Authorization Code + PKCE (S256)
- Dynamic Client Registration (RFC 7591)
- Protected Resource Metadata (RFC 9728)

Enable in Settings → AI Connector → OAuth.

## Admin Settings

Navigate to **Settings → AI Connector** to configure:

- **Desktop AI Clients tab** — install the .mcpb extension and run the inline "+ Add new connection" App Password wizard
- **Web Clients tab** — enable Remote AI Access and OAuth 2.1 for ChatGPT / Claude Web (the OAuth-issued Bearer token is the sole credential for the MCP-over-HTTP transport)
- **Connection Manager tab** — list, rename, revoke, and configure tool access and sensitive-action behavior per MCP connection
- **Image Providers** — API keys for Google Imagen, OpenAI, Unsplash, Pexels
- **Revoke All** — clear all MCP connections (App Passwords + OAuth)

## Security

- **Per-connection privilege model** — every MCP connection authenticates as a WordPress user the administrator chose during setup (their own admin or a dedicated user). Connection capability set + the user's WP role both gate every action.
- **Confirmation tokens** — destructive operations (publish, trash, restore) require explicit confirmation
- **Rate limiting** — per-IP limits on OAuth registration and token endpoints
- **SVG sanitization** — DOMDocument-based stripping of scripts, event handlers, and dangerous URIs
- **CSP headers** — upload portal uses Content-Security-Policy with nonces
- **Token hashing** — upload portal tokens stored as SHA-256 hashes, never in plaintext
- **API key encryption** — image provider keys encrypted with AES-256-CBC using WordPress salts

## Uninstallation

When the plugin is deleted (not just deactivated), it removes:
- All plugin options and settings (including the connections registry)
- All plugin transients (upload sessions, rate limits, OAuth tokens)
- The plugin's custom WordPress capabilities from the administrator role
- .htaccess rules for OAuth discovery

WordPress users and Application Passwords are NOT removed on uninstall — the plugin never created them. Admins can revoke Application Passwords from each user's profile page if they want to clean those up.

## Identifier conventions

All identifiers use the `axtolab_ai_connector_*` / `Axtolab_AI_Connector_*` / `AXTOLAB_AI_CONNECTOR_*` prefix family. The REST namespace is `/wp-json/axtolab-ai-connector/v1`. Stored-option keys are `axtolab_ai_connector_*`; the connections registry lives at `axtolab_ai_connector_connections`.

## License

GPL-2.0-or-later
