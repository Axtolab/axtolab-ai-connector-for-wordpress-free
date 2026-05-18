# WordPress.org Store Assets — Axtolab AI Connector

These assets are required for the WordPress.org plugin listing page.

## Required Assets

### 1. Plugin Banner — `banner-1544x500.png`

**Size:** 1544 x 500 px (also create `banner-772x250.png` for low-res displays)
**Format:** PNG or JPG
**Where it shows:** Top of the plugin page on WordPress.org

**Design brief:**
- Axtolab brand colors and identity
- Plugin name: "Axtolab AI Connector for WordPress"
- Tagline: "Connect AI agents to WordPress"
- Visual elements: suggest showing a connection between an AI icon (brain/circuit) and the WordPress logo, with a clean data flow / bridge metaphor
- Keep text minimal — the plugin name and tagline are already shown by WP.org below the banner
- Dark or gradient background works well (most popular plugins use darker banners for contrast)
- No screenshots in the banner — keep it branding-focused

### 2. Plugin Icon — `icon-256x256.png`

**Size:** 256 x 256 px (also create `icon-128x128.png` for low-res)
**Format:** PNG
**Where it shows:** Plugin search results, installed plugins list, plugin cards

**Design brief:**
- Should work at small sizes (64px display is common)
- Axtolab brand mark or a simplified connector/bridge icon
- Bold, recognizable silhouette — avoid fine detail that disappears at small sizes
- Consistent with Axtolab brand identity
- Consider a stylized "A" with a connection/plug motif, or the Axtolab logo mark if one exists

### 3. Screenshots — `screenshot-1.png`, `screenshot-2.png`, etc.

**Format:** PNG or JPG
**Where they show:** Screenshots tab on the plugin page

Each screenshot needs a caption in readme.txt (already added). Current captions:

1. "AI Connector settings page"
2. "Connect AI Client — connection-token flow"
3. "Upload portal with drag-and-drop"

**How to capture:**
- Install the plugin on a clean WordPress site (testbed.axtolab.com works)
- Navigate to Settings > AI Connector
- Take full-width screenshots of:
  1. **Settings page** — showing the main admin UI with service account status, auth options, image provider settings
  2. **Connect AI Client tab** — showing the "Download Extension (.mcpb)" button and the connection-token generation flow
  3. **Upload portal** — the drag-and-drop upload interface (access via the upload portal URL with a valid token)
- Optional but valuable:
  4. **Connection status** — showing a successfully connected AI agent
  5. **Content created by AI** — a WordPress post editor showing a draft created through the connector

**Screenshot tips:**
- Use a clean WordPress install with default theme (Twenty Twenty-Four)
- Browser width ~1200px for consistent sizing
- Hide browser chrome — capture just the page content
- If using macOS, Cmd+Shift+4 then Space to capture a window cleanly

## File Placement

For WordPress.org SVN, assets go in a separate `assets/` directory at the SVN root (not inside the plugin). But for the GitHub repo, place them here:

```
wp-plugin/axtolab-ai-connector/assets/
├── banner-1544x500.png
├── banner-772x250.png
├── icon-256x256.png
├── icon-128x128.png
├── screenshot-1.png
├── screenshot-2.png
└── screenshot-3.png
```

When submitting to WordPress.org, these get uploaded to the `/assets/` directory in SVN separately from the plugin code.

## Priority

Banner and icon are **required** for a professional listing. Screenshots are strongly recommended — plugins without screenshots get significantly fewer installs.

The banner and icon are the most impactful: they're the first thing users see in search results and on the plugin page.
