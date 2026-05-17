# MCP Server — Axtolab AI Connector for WordPress

Technical reference for the TypeScript MCP server component.

> **Most users do not need this file.** If you just want to connect Claude Desktop to WordPress, download the `.mcpb` installer from the AI Connector admin page after installing the WordPress plugin and double-click it. This document is for developers and advanced/CI users who run the MCP server directly.

## Prerequisites

- WordPress 6.0+ with the [Axtolab AI Connector plugin](https://github.com/Axtolab/axtolab-ai-connector-for-wordpress-free) installed and activated.
- Node.js 18+
- An MCP-compatible client (Claude Desktop, Claude Code, etc.)

## Quick start (CLI install)

```bash
npx wp-mcp-gateway --setup
```

Interactive device-authorization flow:

1. Enter your WordPress site URL.
2. A 6-character code is displayed — enter it under **WordPress Admin → AI Connector → Connect**.
3. Credentials are saved to `~/.wp-mcp-gateway/credentials.json` (mode `0600`).
4. Claude Desktop MCP config is written to `~/.claude/mcp.json`.

## Manage connections

```bash
npx wp-mcp-gateway --list                # Show connected sites
npx wp-mcp-gateway --remove example.com  # Remove a site
```

## Manual configuration (CI / custom setups)

```bash
WP_PLUGIN_BASE_URL=https://example.com/wp-json/axtolab-ai-connector/v1
WP_USERNAME=your-username
WP_APP_PASSWORD=xxxx xxxx xxxx xxxx xxxx xxxx
```

See `.env.example` for all options.

## Available Tools (39)

### Content Management

| Tool | Description |
|------|-------------|
| `wp_getting_started` | Initialize session with editorial workflow guide (call first) |
| `wp_site_info` | Get site metadata and capabilities |
| `wp_list_content_types` | List allowed content types |
| `wp_find_content` | Search content by type, status, author, taxonomy |
| `wp_get_content` | Fetch single item with full edit context |
| `wp_create_draft` | Create a new draft |
| `wp_update_content` | Patch existing content |
| `wp_publish_content` | Publish or schedule (confirmation required) |
| `wp_clone_content` | Clone a post/page as a new draft |
| `wp_get_preview_link` | Get WordPress preview + signed shareable URL |

### Trash & Revisions

| Tool | Description |
|------|-------------|
| `wp_trash_content` | Move to trash (confirmation required) |
| `wp_restore_content` | Restore from trash (confirmation required) |
| `wp_list_revisions` | List all revisions |
| `wp_restore_revision` | Restore a specific revision (confirmation required) |

### Authors & Taxonomies

| Tool | Description |
|------|-------------|
| `wp_list_authors` | List allowlisted authors |
| `wp_assign_author` | Change content author |
| `wp_list_terms` | Search taxonomy terms |
| `wp_create_term` | Create a new term |
| `wp_assign_terms` | Assign terms to content |

### Media

| Tool | Description |
|------|-------------|
| `wp_search_media` | Search the media library |
| `wp_get_media` | Get single media item with metadata |
| `wp_update_media` | Update alt text, caption, description, title |
| `wp_set_featured_image` | Set or remove featured image |
| `wp_upload_media_from_url` | Upload from a URL (server-side download) |
| `wp_find_media_file` | Search local filesystem for an image file |
| `wp_upload_media_from_path` | Upload from a local file path |

### Inline Images

| Tool | Description |
|------|-------------|
| `wp_insert_inline_image` | Insert image into content with block-aware placement |
| `wp_replace_inline_image` | Replace an inline image reference |
| `wp_remove_inline_image` | Remove an inline image from content |

### Yoast SEO

| Tool | Description |
|------|-------------|
| `wp_get_yoast_analysis` | Get readability and SEO scores |
| `wp_update_yoast_metadata` | Update focus keyphrase, meta description, SEO title |
| `wp_get_yoast_head_preview` | Preview the rendered head/meta tags |

### Image Generation & Stock Photos

| Tool | Description |
|------|-------------|
| `wp_generate_image` | Generate an image with AI (Google Imagen or OpenAI) |
| `wp_search_stock_photos` | Search Unsplash or Pexels |
| `wp_import_stock_photo` | Import a stock photo with automatic attribution |
| `wp_list_image_providers` | Show which providers are configured |
| `wp_confirm_image` | Confirm a generated image to prevent auto-cleanup |

### Upload Portal

| Tool | Description |
|------|-------------|
| `wp_create_upload_session` | Create a temporary drag-and-drop upload link (15 min) |
| `wp_get_upload_session` | Retrieve uploaded file details after user finishes |

---

## Configuration

| Variable | Default | Description |
|----------|---------|-------------|
| `WP_MCP_SITE` | — | Hostname to load from token store (auto-set by `--setup`) |
| `WP_PLUGIN_BASE_URL` | — | Full REST base URL (legacy mode) |
| `WP_USERNAME` | — | WordPress username (legacy mode) |
| `WP_APP_PASSWORD` | — | Application password (legacy mode) |
| `ALLOWED_CONTENT_TYPES` | `post,page,featured_item` | Comma-separated content types |
| `ALLOWED_TAXONOMIES` | `category,post_tag,...` | Comma-separated taxonomies |
| `ALLOWED_AUTHOR_IDS` | (all) | Comma-separated author IDs |
| `MEDIA_MAX_SIZE_MB` | `10` | Max upload size in MB |
| `MEDIA_REQUIRE_ALT_TEXT` | `true` | Require alt text on uploads |
| `RATE_LIMIT_MAX_BURST` | `30` | Token bucket burst capacity |
| `RATE_LIMIT_REFILL_PER_SECOND` | `1` | Token bucket refill rate |
| `REQUEST_TIMEOUT_MS` | `30000` | HTTP request timeout |
| `CONFIRMATION_TTL_SECONDS` | `300` | Confirmation token expiry |

---

## Authentication

The plugin supports four authentication methods:

1. **Application Passwords** (HTTP Basic Auth) — default for local MCP clients
2. **Device Authorization** (RFC 8628) — used by `--setup` CLI flow
3. **OAuth 2.1** (PKCE S256) — for web-based clients (ChatGPT, Claude Web)
4. **Bearer Token** — for remote MCP-over-HTTP transport

---

## Development

```bash
npm run dev        # Run with tsx (hot reload)
npm run build      # Compile TypeScript
npm start          # Run compiled output
npm test           # Run tests (Vitest)
npm run test:watch # Watch mode
```

---

## Security

- **Confirmation tokens** — destructive actions (publish, trash, restore) require a single-use token
- **Rate limiting** — token bucket rate limiter on all tool calls
- **Policy enforcement** — dual-layer (TypeScript client + PHP server) allowlist validation
- **Credential isolation** — secrets stored in `~/.wp-mcp-gateway/credentials.json` (mode `0600`), never in MCP config
- **SVG sanitization** — upload portal strips scripts, event handlers, and dangerous attributes
- **CSP headers** — upload portal pages use Content-Security-Policy with nonces

---

## License

MIT
