=== Axtolab AI Connector ===
Contributors: axtolab
Tags: ai, mcp, claude, chatgpt, automation
Requires at least: 6.2
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Let AI agents read, draft, edit, and publish WordPress content safely. One-click Roll Back on every write.

== Description ==

Axtolab AI Connector for WordPress connects your WordPress site to AI agents like Claude, ChatGPT, and any MCP-compatible tool. AI agents can safely create drafts, edit content, manage media, work with taxonomies, and integrate with Yoast SEO — all through a secure, permission-controlled gateway with **Roll Back / Undo** on every write.

**How it works:** The plugin adds a REST API gateway to your WordPress site. A lightweight MCP server runs on your local machine and translates AI tool calls into WordPress REST requests. The plugin enforces permissions, validates requests, captures every write into a Roll Back / Undo changelog, and logs actions.

= Key Features =

* **Roll Back / Undo on every write** — every AI-driven change captures a before/after snapshot. Revert any tool call with one click from the Logs & Roll Back admin page.
* **Content Management** — Create, edit, and manage posts, pages, and custom post types. Clone existing content as drafts. View and restore revisions. Generate shareable preview links.
* **Media Library** — Upload images from URL, local file, or drag-and-drop portal. Search and browse existing media. Set featured images. Insert, replace, and remove inline images.
* **Stock Photos** — Search and import free stock photos from Unsplash and Pexels with automatic attribution.
* **Yoast SEO** — Read SEO and readability scores. Update focus keyphrase, SEO title, and meta description. Preview rendered meta tags.
* **Authors & Taxonomies** — Assign authors from an allowlist. Create and assign categories, tags, and custom taxonomy terms.
* **Secure by Design** — Dedicated service account with minimal permissions, created only after explicit administrator consent (no silent user creation on activation). Confirmation tokens required for publish, trash, and restore operations. Allowlist-driven content type and taxonomy controls. Rate limiting on authentication endpoints.
* **Multiple Auth Methods** — Application Passwords, OAuth 2.1 with PKCE, and Bearer Token for MCP-over-HTTP transport.
* **Upload Portal** — Drag-and-drop media uploads with time-limited tokens. No WordPress login required for the upload session.

= Available MCP Tools =

The connector exposes a categorized tool surface to MCP-compatible AI clients. All tools are documented and discoverable via the standard MCP `tools/list` JSON-RPC method.

* **Search & Discovery** — `wp_find_content` (filter + search), `wp_list_content_types`, `wp_get_content`, `wp_site_info`, `wp_getting_started`
* **Content Authoring** — `wp_create_draft`, `wp_update_content`, `wp_publish_content`, `wp_clone_content`, `wp_get_preview_link`
* **Trash & Revisions** — `wp_trash_content`, `wp_restore_content`, `wp_list_revisions`, `wp_restore_revision`
* **Media** — `wp_search_media`, `wp_get_media`, `wp_update_media`, `wp_set_featured_image`, `wp_upload_media_from_url`
* **Inline Images (Gutenberg-aware)** — `wp_insert_inline_image`, `wp_replace_inline_image`, `wp_remove_inline_image`
* **Authors & Taxonomy** — `wp_list_authors`, `wp_assign_author`, `wp_list_terms`, `wp_create_term`, `wp_assign_terms`
* **Yoast SEO** — `wp_get_yoast_analysis`, `wp_update_yoast_metadata`, `wp_get_yoast_head_preview`
* **Stock Photos** — `wp_search_stock_photos`, `wp_import_stock_photo` (Unsplash + Pexels with auto-attribution)
* **Image Providers** — `wp_generate_image`, `wp_list_image_providers`, `wp_confirm_image` with site-owner supplied provider API keys
* **Upload Portal** — `wp_create_upload_session`, `wp_get_upload_session` (drag-and-drop with time-limited tokens)
* **Connection Introspection** — `wp_get_my_capabilities` returns the calling connection's capability groups, named preset (if any), and the resolved tool list — letting AI agents plan work without trial-and-error.
* **Custom Post Type support** — Default allowlist accepts `post`, `page`, `featured_item`. To accept ANY registered public post type (e.g. WooCommerce `product`, EDD `download`, custom CPTs), set the allowlist to `["*"]` in MCP Gateway settings. `wp_list_content_types` then expands the wildcard to the live list of public types on the site.
* **Audit Log** — every tool call is recorded with timestamp, source, and redacted parameters
* **Rate Limiting** — per-IP token bucket on every authenticated endpoint

= What's Included =

Axtolab AI Connector is **fully featured with no limits**. No publish limits, no connection caps, no feature gates.

**Everything included free:**

* Create, edit, publish, and schedule content with AI agents
* Unlimited connections — connect Claude, ChatGPT, and any MCP agent
* Upload and manage media (URL, local file, drag-and-drop portal)
* Search and import stock photos (Unsplash, Pexels)
* Generate images through supported providers when you configure your own provider API key
* Yoast SEO integration
* All authentication methods (App Passwords, OAuth 2.1, Bearer Token)
* All taxonomy and author management

The free core is feature-complete and useful on its own. Separate plugins may extend it, but no built-in feature in this WordPress.org package requires a license key.

= Getting Started =

1. Install and activate the plugin
2. Go to AI Connector in wp-admin and generate a connection token
3. Click the "Download Extension (.mcpb)" button on the setup page to grab the Claude Desktop bundle from GitHub Releases, then drag it into Claude Desktop's Extensions panel — or connect a compatible client through MCP-over-HTTP
4. Start using your AI agent with WordPress

== Installation ==

1. Upload the `axtolab-ai-connector` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu
3. Go to Settings > AI Connector
4. Click **Create service user** when the one-time consent notice appears at the top of the page

The plugin uses a dedicated WordPress user (`axtolab-connector-service`) with a custom limited-permission role (`axtolab_ai_connector_editor`) as the API actor for every AI tool call. By design, this user is **not** created on plugin activation — the AI Connector waits for an administrator to give explicit consent on the settings page first, in line with WordPress.org plugin review guidelines. Once you click "Create service user", the plugin creates the user + role and you can immediately generate connection tokens. The user has no admin / settings / user-management permissions; uninstalling the plugin removes it.

= About the `.mcpb` Claude Desktop installer =

The Claude Desktop installer bundle (`.mcpb`) is distributed as a GitHub Release asset rather than bundled inside this plugin's ZIP. The "Download Extension (.mcpb)" button in the AI Connector setup page links to the published release at GitHub; the plugin itself does not bundle, host, execute, or auto-install the file. Site administrators who choose to use Claude Desktop click the button, the browser downloads the file from GitHub, and they then drag it into Claude Desktop's Extensions panel. See the External Services section below for details.

= Requirements =

* WordPress 6.2 or later
* PHP 7.4 or later

== Frequently Asked Questions ==

= What AI agents does this work with? =

Any MCP-compatible AI agent, including Claude (via Claude Desktop or Claude Code), ChatGPT (via MCP plugins), and custom agents built with the MCP SDK.

= Can AI agents publish content directly? =

Yes. The AI Connector supports full content workflows including creating, editing, publishing, and scheduling content. No limits on publishing.

= Is my content sent to external services? =

The plugin itself does not send your content to an Axtolab-hosted service. Content stays between your WordPress site and the MCP client/server you choose to run. Optional stock-photo and AI-image tools connect to their configured provider APIs only when you explicitly use those tools — see the External Services section below.

= Does this work with multisite? =

Yes. Single-site and multisite installations are supported by the free core.

= How is authentication handled? =

The plugin supports three authentication methods: WordPress Application Passwords, OAuth 2.1 with PKCE, and Bearer Tokens. All methods use a single dedicated service account (`axtolab-connector-service`) that an administrator must explicitly create from the AI Connector settings page after activation — the plugin never creates WordPress users without your consent. The role assigned to the service user (`axtolab_ai_connector_editor`) is limited to editing posts, pages, media, and categories.

= Which auth method works for which endpoint? =

Each method has a specific scope. Pick the one that matches what your client needs to do:

* **Application Password** (HTTP Basic Auth) — works against every plugin REST endpoint (`/site-info`, `/content/*`, `/media/*`, `/yoast/*`, `/abilities/*`, etc.). This is available for custom clients and local MCP clients that need direct REST access. The wp-admin connection-token flow can create the necessary credentials without manually copying a WordPress password.
* **OAuth 2.1 with PKCE + Bearer Token** — issued tokens are scoped to the **MCP-over-HTTP transport only** (`/wp-json/axtolab-ai-connector/v1/mcp`). They do not authenticate the ordinary REST API surface. This scope split is intentional: OAuth is the path remote/web MCP clients (ChatGPT, Claude Web) use to access the JSON-RPC tool surface, while ordinary REST is reserved for trusted local MCP servers using Application Passwords. If you need OAuth-issued credentials to call ordinary REST endpoints directly, contact support — we can extend the scope on request once your use case is documented.

= The AI Connector admin shows "Host-root .well-known discovery: Blocked by your web server". What does this mean? =

This is informational, not an error — your AI Connector is working correctly.

The plugin tries to publish OAuth discovery metadata at three locations: the standard RFC 8414/9728 paths (`/.well-known/oauth-protected-resource` and `/.well-known/oauth-authorization-server`), an issuer-relative path used by ChatGPT (`/wp-json/axtolab-ai-connector/v1/.well-known/...`), and direct REST endpoints (`/wp-json/axtolab-ai-connector/v1/oauth/metadata/...`). Real-world MCP clients (Claude Desktop, ChatGPT, Claude Web) use the latter two paths and do not need the host-root paths to work.

If your site is on a host where nginx sits in front of Apache or LiteSpeed (most managed WordPress hosts including SiteGround, WP Engine, Kinsta, Cloudways), nginx serves `/.well-known/*` paths directly and never forwards them to PHP. The plugin's `.htaccess` rewrite is therefore bypassed at the nginx layer — that's what the warning detects. MCP client connectivity is unaffected.

If you do want full RFC 8414/9728 compliance (e.g. you're integrating with strict OAuth validators), ask your host to add the following nginx configuration block — typically inserted before any catch-all `.well-known` location and before the `index.php` fallback:

`location ~ ^/\.well-known/oauth-(protected-resource|authorization-server)$ {`
`    try_files $uri /index.php$is_args$args;`
`}`

After the host applies this and reloads nginx, the warning will clear within 6 hours (or sooner if you visit the AI Connector admin page).

= Are Claude, ChatGPT, OpenAI, and WordPress affiliated with this plugin? =

No. Claude, ChatGPT, OpenAI, and WordPress are trademarks of their respective owners. Axtolab AI Connector is not affiliated with, endorsed by, or sponsored by Anthropic, OpenAI, or the WordPress Foundation.

== External Services ==

This plugin connects to the following external services only when the corresponding feature is configured or used. Provider API keys are supplied by the site owner. The plugin does not send telemetry or analytics to Axtolab.

= Claude (Anthropic) — OAuth Callback =

* **Service:** Anthropic's Claude products at https://claude.ai and https://claude.com
* **Type:** OAuth 2.1 authorization-code callback redirect (no inbound network call from Anthropic during this step)
* **Endpoints (registered redirect URIs):**
    * `https://claude.ai/api/mcp/auth_callback`
    * `https://claude.com/api/mcp/auth_callback`
* **When used:** When a logged-in WordPress administrator authorizes a Claude AI client to connect to their WordPress site via the plugin's OAuth 2.1 flow. The plugin's `/oauth/authorize` endpoint redirects the administrator's browser to one of these URLs after consent.
* **Data sent in redirect:** A one-time, short-lived OAuth authorization code plus the original `state` parameter. No personal data, no site content, no credentials.
* **Terms:** [Anthropic Consumer Terms](https://www.anthropic.com/legal/consumer-terms)
* **Privacy:** [Anthropic Privacy Policy](https://www.anthropic.com/legal/privacy)

= ChatGPT (OpenAI) — OAuth Callback =

* **Service:** OpenAI's ChatGPT custom-connector platform and OpenAI apps platform
* **Type:** OAuth 2.1 authorization-code callback redirect (no inbound network call from OpenAI during this step)
* **Endpoints (registered redirect URIs):**
    * `https://chatgpt.com/connector_platform_oauth_redirect`
    * `https://platform.openai.com/apps-manage/oauth`
* **When used:** When a logged-in WordPress administrator authorizes ChatGPT (or an OpenAI Apps Platform client) to connect to their WordPress site via the plugin's OAuth 2.1 flow. The plugin's `/oauth/authorize` endpoint redirects the administrator's browser to one of these URLs after consent.
* **Data sent in redirect:** A one-time, short-lived OAuth authorization code plus the original `state` parameter. No personal data, no site content, no credentials.
* **Terms:** [OpenAI Terms of Use](https://openai.com/policies/terms-of-use)
* **Privacy:** [OpenAI Privacy Policy](https://openai.com/policies/privacy-policy)

= GitHub Releases (Claude Desktop installer bundle download) =

* **Service:** GitHub Releases — the Axtolab AI Connector for WordPress (Free) public repository
* **Type:** Static binary file download (HTTP GET, no inbound request from GitHub to the WordPress site)
* **Endpoint:** API host `github.com`, path `/Axtolab/axtolab-ai-connector-for-wordpress-free/releases/latest/download/axtolab-ai-connector.mcpb` (HTTPS).
* **When used:** When a logged-in WordPress administrator clicks the "Download Extension (.mcpb)" button on the AI Connector setup page. The administrator's browser is redirected to GitHub Releases to fetch the file. The plugin itself does not initiate an outbound request to GitHub; the user's browser does, when they click the link. Filterable via `axtolab_ai_connector_mcpb_download_url` if a site owner wants to self-host the bundle.
* **Data sent:** Standard HTTP browser request headers only. No WordPress site data, content, credentials, or telemetry is sent.
* **Terms:** [GitHub Terms of Service](https://docs.github.com/en/site-policy/github-terms/github-terms-of-service)
* **Privacy:** [GitHub Privacy Statement](https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement)

= Local MCP clients (Claude Desktop, Cursor, Claude Code, VS Code) =

* **Service:** A local AI client running on the site administrator's own computer.
* **When used:** When the site administrator installs the `.mcpb` bundle (downloaded from GitHub Releases via the AI Connector setup page) into a local AI client such as Claude Desktop. That client then connects inbound to the site's REST API using credentials issued during setup. The plugin does not initiate any outbound network call to these clients.
* **Data sent:** Only what the AI client requests through tool calls the administrator has approved. The plugin does not send unsolicited data.

= Unsplash (Stock Photo Search) =

* **Service:** [Unsplash](https://unsplash.com/)
* **Endpoint:** API host `api.unsplash.com`, path `/search/photos` (HTTPS).
* **When used:** When an AI agent searches for stock photos via the stock photo tool
* **Data sent:** Search query, orientation preference
* **Terms:** [Unsplash Terms](https://unsplash.com/terms)
* **Privacy:** [Unsplash Privacy Policy](https://unsplash.com/privacy)

= Pexels (Stock Photo Search) =

* **Service:** [Pexels](https://www.pexels.com/)
* **Endpoint:** API host `api.pexels.com`, path `/v1/search` (HTTPS).
* **When used:** When an AI agent searches for stock photos via the stock photo tool
* **Data sent:** Search query, orientation preference
* **Terms:** [Pexels Terms of Service](https://www.pexels.com/terms-of-service/)
* **Privacy:** [Pexels Privacy Policy](https://www.pexels.com/privacy-policy/)

= Google Imagen (AI Image Generation) =

* **Service:** [Google Cloud AI](https://cloud.google.com/vertex-ai/generative-ai/docs/image/overview)
* **Endpoint:** API host `generativelanguage.googleapis.com`, path `/v1beta/models/imagen-3.0-generate-002:predict` (HTTPS).
* **When used:** When an AI agent generates images using the Google Imagen provider and the site owner has configured a Google API key
* **Data sent:** Text prompt, aspect ratio, safety filter level
* **Terms:** [Google Cloud Terms](https://cloud.google.com/terms)
* **Privacy:** [Google Privacy Policy](https://policies.google.com/privacy)

= OpenAI (AI Image Generation) =

* **Service:** [OpenAI](https://openai.com/)
* **Endpoint:** API host `api.openai.com`, path `/v1/images/generations` (HTTPS).
* **When used:** When an AI agent generates images using the OpenAI provider and the site owner has configured an OpenAI API key
* **Data sent:** Text prompt, image size, quality setting
* **Terms:** [OpenAI Terms of Use](https://openai.com/policies/terms-of-use)
* **Privacy:** [OpenAI Privacy Policy](https://openai.com/policies/privacy-policy)

All API keys are stored encrypted using AES-256-CBC with WordPress security salts and are only decrypted at the time of the API call.

== Screenshots ==

1. AI Connector setup page with desktop AI client quick connect and connection status.
2. Logs & Roll Back admin page where AI-driven writes appear for review and revert.
3. Connection-token tab for desktop clients — paste the wmcp1_... token into the AI client's extension settings.
4. Web client setup tab for ChatGPT, Claude Web, and MCP-compatible clients.
5. Image Providers tab for stock-photo and AI image provider configuration.
6. Connection capabilities tab for choosing what each AI client can do.

== Support ==

* **WordPress.org plugin support forum** — https://wordpress.org/support/plugin/axtolab-ai-connector/ (please use this for general questions; replies are public so the next merchant searching for the same answer can find it).
* **Email support** — support@axtolab.com (for license, account, or anything sensitive).
* **Documentation** — https://axtolab.com/docs/ai-connector
* **Bug reports** — use the WordPress.org support forum for public issues or email support for sensitive reports.

The same support entry points are also surfaced inside wp-admin: a "Need help?" footer on every Axtolab settings page, and "Settings · Support" / "Email support · WordPress.org forum · Docs" rows on the Plugins admin row for this plugin.

== Changelog ==

= 1.0.0 =
First public WordPress.org release.

Highlights:

* **WordPress 6.9 QA verified.** Tested on a real WordPress 6.9 testbed, including the Abilities API bridge, admin UI, media/upload flows, auth methods, and deactivate/reactivate behaviour.
* **Health and ping endpoints.** `/wp-json/axtolab-ai-connector/v1/ping` and `/health-check` report connection status and the installed plugin version.
* **Roll Back / Undo on every write.** Every AI-driven create, update, publish, trash, or restore action captures a before/after snapshot. Revert any change with one click from the Logs & Roll Back admin page.
* **MCP tools** across content authoring, media management, taxonomy, authors, Yoast SEO, stock photos, image generation, upload portal, connection introspection, and Roll Back.
* **Three authentication methods.** Application Passwords (HTTP Basic), OAuth 2.1 with PKCE S256 + dynamic client registration (RFC 7591), and Bearer Token for MCP-over-HTTP transport.
* **Capability-group-driven tool filtering** on the MCP transport — operators choose what AI agents on each connection are allowed to do (read, create_edit, publish, trash_restore, media_manage, taxonomy, authors, seo, image, upload_portal).
* **Provider-neutral SEO tools** that auto-detect Yoast or Rank Math, with the legacy Yoast-specific tools retained for backwards compatibility.
* **Confirmation-token flow** required for destructive operations (publish, trash, restore) — a single-use token must be issued before the action proceeds.
* **Service account isolation** — a dedicated `axtolab-connector-service` user with the minimal `axtolab_ai_connector_editor` role; the plugin's REST routes never run as the logged-in admin.
* **Submission readiness pass** — feature settings stand alone (no inline upsells), all third-party services disclosed in the External Services section, the `.mcpb` Claude Desktop installer is hosted as a GitHub Release asset and linked from the setup page.

== Upgrade Notice ==

= 1.0.0 =
First public WordPress.org release. See the changelog for the full feature list.
