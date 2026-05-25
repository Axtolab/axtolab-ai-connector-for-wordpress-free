# Axtolab AI Connector for WordPress Free

> Let AI agents safely read, draft, edit, and publish WordPress content. One-click Roll Back on every write.

[![Latest release](https://img.shields.io/github/v/release/Axtolab/axtolab-ai-connector-for-wordpress-free?label=latest&color=2ea44f)](https://github.com/Axtolab/axtolab-ai-connector-for-wordpress-free/releases/latest)
[![License: GPL v2](https://img.shields.io/badge/License-GPL_v2%2B-blue.svg)](LICENSE)
[![WordPress 6.0+](https://img.shields.io/badge/WordPress-6.0%2B-21759b)](https://wordpress.org)
[![PHP 7.4+](https://img.shields.io/badge/PHP-7.4%2B-777bb4)](https://www.php.net/)
[![MCP compatible](https://img.shields.io/badge/MCP-compatible-9c27b0)](https://modelcontextprotocol.io/)

---

## 📥 Download v1.0.0

**Easiest:** install from the WordPress.org plugin directory once approved — search **"Axtolab AI Connector"** in **WordPress Admin → Plugins → Add New**.

**Pre-approval, or for advanced users:** grab the latest release artifacts here.

| File | What it's for | Size | Direct download |
|---|---|---|---|
| 🔌 **`axtolab-ai-connector-1.0.0.zip`** | WordPress plugin — upload via WordPress Admin → Plugins → Add New → Upload Plugin | 460 KB | **[Download ZIP →](https://github.com/Axtolab/axtolab-ai-connector-for-wordpress-free/releases/latest/download/axtolab-ai-connector-1.0.0.zip)** |
| 🖥️ **`axtolab-ai-connector.mcpb`** | Claude Desktop installer — double-click after the plugin is set up | 300 KB | **[Download .mcpb →](https://github.com/Axtolab/axtolab-ai-connector-for-wordpress-free/releases/latest/download/axtolab-ai-connector.mcpb)** |

→ See the [Releases page](https://github.com/Axtolab/axtolab-ai-connector-for-wordpress-free/releases) for full changelogs and SHA-256 checksums.

→ **Full documentation:** [axtolab.com/docs/ai-connector](https://axtolab.com/docs/ai-connector) (Getting started, Installation, OAuth setup, Supported tools, Security, Troubleshooting).

→ Quick install + connect-a-client instructions are also below in [Install](#install).

---

## Two free builds

Axtolab AI Connector ships in two flavors — both free, both GPL-2.0-or-later, both with no license key. Pick whichever fits your install path.

- **AI Connector for WordPress (Free)** — this repository. Distributed via the WordPress.org plugin directory. Optimized for the standard WordPress install path: one-click install from wp-admin, auto-updates through WordPress. Standalone and feature-complete on its own.
- **AI Connector Core** — downloaded from [axtolab.com/products/ai-connector](https://axtolab.com/products/ai-connector). Same MCP gateway, plus the shared integration surface that future Axtolab add-on plugins extend. A free Axtolab account is required for the download (used for update notifications and add-on management when add-ons ship).

Either build works on its own; you don't need both. Pick the **Free build** if you want the WordPress.org install path. Pick **Core** if you're planning to install Axtolab add-ons alongside.

---

## What it does

Axtolab AI Connector for WordPress (Free) connects your WordPress site to AI clients like **Claude Desktop**, **Claude Web**, **ChatGPT**, and any MCP-compatible tool. AI clients can create drafts, edit content, manage media, work with taxonomies, and integrate with Yoast SEO — all through a permission-controlled gateway with **Roll Back / Undo** on every write.

## How it works

The plugin adds a REST API gateway to your WordPress site. AI clients connect to that gateway over MCP — either locally (via the bundled `.mcpb` installer for Claude Desktop) or remotely (via OAuth 2.1 for Claude Web, ChatGPT, and other web-based MCP clients). The plugin enforces permissions, validates requests, captures every write into a Roll Back / Undo changelog, and logs actions.

## Key features

- **Roll Back / Undo on every write** — revert any AI-driven change with one click from the Logs & Roll Back admin page.
- **Content management** — create, edit, and manage posts, pages, and custom post types. Clone content as drafts, view and restore revisions, generate shareable preview links.
- **Media library** — upload from URL, local file, or drag-and-drop portal. Set featured images. Insert, replace, and remove inline images.
- **Stock photos** — search and import free stock photos from Unsplash and Pexels with automatic attribution.
- **AI image generation** — Google Imagen and OpenAI image models using your own provider API keys.
- **Yoast SEO** — read scores, update focus keyphrase, SEO title, and meta description.
- **Secure by design** — dedicated service account with minimal permissions, allowlist-driven content type controls, single-use confirmation tokens for destructive actions, rate limiting on authentication endpoints.
- **Two authentication methods** — Application Passwords for Desktop AI clients, and OAuth 2.1 with PKCE for Web AI clients on the MCP-over-HTTP transport.

## Bring your own AI model

The plugin uses the open **Model Context Protocol**, so the AI client and the model behind it are your choice. The `.mcpb` bundle is a portable MCP server — install it in any MCP-compatible client, then point that client at whichever model you want.

- **Cloud models:** Claude Desktop / Web, ChatGPT, Cursor, Claude Code
- **Local on-device models:** Goose, Continue.dev, Cline, LibreChat, Open WebUI, or any custom MCP client paired with **Ollama**, **LM Studio**, **llama.cpp**, etc.

Your content stays on your WordPress site. Your AI conversation stays wherever your client routes it — including fully on-device for privacy-sensitive workflows (legal, healthcare, regulated industries) or cost-sensitive setups.

*One caveat: the optional `wp_generate_image` tool calls cloud providers (Google Imagen or OpenAI) for image synthesis. All other tools — content authoring, media management, Yoast SEO, taxonomy, Roll Back — are fully model-agnostic.*

## Install

### 1. Install the plugin

**From WordPress.org** *(recommended once approved)*:

Search for **Axtolab AI Connector** in **WordPress Admin → Plugins → Add New**, install, and activate.

**From this repository**:

Download the latest release zip from the [Releases page](https://github.com/Axtolab/axtolab-ai-connector-for-wordpress-free/releases), then upload it in **WordPress Admin → Plugins → Add New → Upload Plugin** and activate.

The plugin creates a dedicated `axtolab-connector-service` user with the minimal `axtolab_ai_connector_editor` role. No manual user setup needed.

### 2. Connect an AI client

Open **WordPress Admin → AI Connector** to find the setup page. Pick the flow that matches your client:

#### Desktop: Claude Desktop, Claude Code

1. Click **Download Extension (.mcpb)** in the AI Connector setup page.
2. Double-click the downloaded `.mcpb` file to install it in Claude Desktop.
3. In WordPress, generate a connection token, paste it into the extension's setup screen.
4. Done — start using your AI client with WordPress.

#### Web: Claude Web, ChatGPT, MCP-over-HTTP clients

1. In the AI Connector setup page, open the **Web Client** tab.
2. Enable OAuth and copy the connector URL (`https://your-site.com/wp-json/axtolab-ai-connector/v1/mcp`).
3. Add it as a custom MCP connector in your AI client (Claude Web → Settings → Connectors; ChatGPT → Custom Connectors).
4. Authorize via the OAuth flow when prompted.

#### Direct REST (advanced)

Application Passwords work against every plugin REST endpoint for clients that need direct access. Generate one in **WordPress Admin → Users → Profile → Application Passwords**, then point your client at `/wp-json/axtolab-ai-connector/v1/`.

## Usage

Once connected, talk to your AI client naturally:

- *"Write a blog post about AI trends in manufacturing and save it as a draft."*
- *"Find my latest draft and update the featured image to something on-brand."*
- *"List all posts in the 'Industry News' category."*
- *"Generate a hero image for the new product launch page."*

Destructive actions (publish, trash, restore) require a single-use confirmation token. The AI client requests one, presents it for you to acknowledge, then proceeds. Nothing destructive happens without explicit confirmation.

## Security model

- **Allowlist-driven** — only configured content types, taxonomies, and authors are accessible.
- **Capability-checked** — every operation verifies WordPress user permissions.
- **Confirmation required** — publishing, trashing, and revision restores require explicit single-use tokens.
- **No permanent delete** — trash only; permanent deletion is disabled.
- **Roll Back on every write** — full audit trail with one-click revert.
- **Service account isolation** — REST routes act as a minimal-permission service user, never as the logged-in admin.
- **Encrypted provider keys** — image-provider API keys are stored with AES-256-CBC using WordPress security salts.

Full capability matrix: [docs/security/capability-matrix.md](docs/security/capability-matrix.md).

## Documentation

- **Plugin admin help** — every Axtolab admin page has a "Need help?" footer.
- **Online docs** — https://axtolab.com/docs/ai-connector

## Support

- **WordPress.org support forum** — https://wordpress.org/support/plugin/axtolab-ai-connector/
- **Email support** — support@axtolab.com
- **Bug reports** — open an issue in this repository, or use the WordPress.org forum for public issues.

## License

GPLv2 or later. See [LICENSE](LICENSE).

Claude, ChatGPT, OpenAI, and WordPress are trademarks of their respective owners. Axtolab AI Connector is not affiliated with, endorsed by, or sponsored by Anthropic, OpenAI, or the WordPress Foundation.
