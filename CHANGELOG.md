# Changelog

All notable changes to this project are documented in this file.

## [1.0.0] — 2026-05-17

First public release.

### Added
- **Roll Back / Undo on every write.** Every AI-driven create, update, publish, trash, or restore captures a before/after snapshot. Revert any change with one click from the Logs & Roll Back admin page.
- **MCP tool surface** across content authoring, media management, taxonomy, authors, Yoast SEO, stock photos, image generation, upload portal, connection introspection, and Roll Back.
- **Four authentication methods:** WordPress Application Passwords (HTTP Basic), Device Authorization (RFC 8628), OAuth 2.1 with PKCE S256 + Dynamic Client Registration (RFC 7591), and Bearer Token for MCP-over-HTTP transport.
- **Capability-group-driven tool filtering** on the MCP transport — operators choose what AI clients on each connection are allowed to do (read, create_edit, publish, trash_restore, media_manage, taxonomy, authors, seo, image, upload_portal).
- **Provider-neutral SEO tools** that auto-detect Yoast or Rank Math.
- **Confirmation-token flow** required for destructive operations (publish, trash, restore).
- **Service account isolation:** a dedicated `axtolab-connector-service` user with the minimal `axtolab_ai_connector_editor` role; the plugin's REST routes never run as the logged-in admin.
- **Bundled `.mcpb` Claude Desktop installer** available as a passive download from the AI Connector setup page.
- **Health and ping endpoints** at `/wp-json/axtolab-ai-connector/v1/ping` and `/health-check` for connection status and plugin version.
- **WordPress 6.9 Abilities API bridge.**

### Security
- All third-party services disclosed in the plugin readme's External Services section.
- API keys for image providers stored encrypted (AES-256-CBC with WordPress security salts), decrypted only at the time of the API call.
- Per-IP rate limiting on authentication endpoints.
- Confirmation tokens are single-use and time-limited.
