# MCP Server — Axtolab AI Connector for WordPress

Technical reference for the TypeScript MCP server component.

> **You do not install this directly.** The MCP server is packaged inside the `.mcpb` Claude Desktop installer that ships with each plugin release. Customers download the `.mcpb` from the AI Connector admin page (or from the [GitHub Release](https://github.com/Axtolab/axtolab-ai-connector-for-wordpress-free/releases/latest)) and double-click to install — Claude Desktop launches the server with the right credentials automatically.
>
> This file is for **developers building or extending the plugin** who want to run the server directly from source.

## Prerequisites

- WordPress 6.0+ with the [Axtolab AI Connector plugin](https://github.com/Axtolab/axtolab-ai-connector-for-wordpress-free) installed and activated.
- Node.js 18+ (for source builds).
- An MCP-compatible client (Claude Desktop, Cursor, Continue.dev, custom client, etc.).

## How the server gets credentials

The MCP server expects credentials via either of two paths, in order:

1. **Token-store mode** — credentials JSON at `~/.wp-mcp-gateway/credentials.json` keyed by hostname. The `.mcpb` writes/reads this file. AI clients can also create entries at runtime by calling the `wp_connect_site` MCP tool with a connection token from the AI Connector admin page (token starts with `wmcp1_`).
2. **Environment-variable mode** — set `WP_PLUGIN_BASE_URL`, `WP_USERNAME`, `WP_APP_PASSWORD`. Useful for CI or for custom hosts wrapping the server.

See `.env.example` for all options.

## Running from source

```bash
npm install
npm run build          # Compile TypeScript to dist/
node dist/index.js     # Run the stdio MCP server
```

For development with hot reload:

```bash
npm run dev
```

## Bundling for Claude Desktop

The plugin's `scripts/package-plugin.sh` runs `npm run build:mcpb` here as part of the release flow. The output `.mcpb` is uploaded as a GitHub Release asset.

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

The plugin supports two authentication methods:

1. **Application Passwords** (HTTP Basic Auth) — primary path for local MCP clients; credentials delivered via the `.mcpb` extension or the runtime `wp_connect_site` MCP tool with a connection token
2. **OAuth 2.1** (PKCE S256) — for web-based clients (ChatGPT, Claude Web); includes dynamic client registration. OAuth issues standard `Authorization: Bearer ...` tokens that the MCP-over-HTTP transport then verifies.

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
