# Page Format Guide (Flatsome Theme)

## Overview
Pages using Flatsome are **layout-driven**. Nearly the entire page is built with shortcodes organized in a strict hierarchy: sections contain rows, rows contain columns, columns contain content.

**Critical:** Use `content_format: "html"` for all pages with shortcodes.

## Layout Hierarchy

```
[section] ← Full-width container with background, padding, video/parallax
  ↓
[row] ← Horizontal grid container
  ↓
[col] ← Column (width controlled by span)
  ↓
[Content blocks: text, images, buttons, etc.]
```

Nested layouts use `[row_inner]` and `[col_inner]` inside columns.

## Core Section Types

### Hero Section with Video Background
```
[section bg_overlay="rgba(0,57,82,0.657)" dark="true" padding="140px" video_mp4="https://..." video_webm="https://..." video_visibility="visible"]
  [row v_align="middle" h_align="center"]
    [col span="6" span__sm="12"]
      [ux_text font_size="2.5" line_height="1.5" text_align="center"]
        <h1>Hero Headline</h1>
        <p>Hero subtitle text</p>
      [/ux_text]
    [/col]
  [/row]
[/section]
```

**Parameters:**
- `bg_overlay`: RGBA color overlay (brand dark blue: `rgba(0,57,82,0.657)`)
- `dark`: "true" for dark text overlay
- `padding`: "140px" (hero sections typically generous padding)
- `video_mp4`: Full URL to MP4 video file
- `video_webm`: Full URL to WebM video file (fallback)
- `video_visibility`: "visible" to show video background

### Parallax Background Section
```
[section bg="MEDIA_ID" bg_size="original" bg_overlay="rgba(0,57,82,0.669)" parallax="3" padding="101px"]
  [row]
    [col span="8" span__sm="12"]
      Content here
    [/col]
  [/row]
[/section]
```

**Parameters:**
- `bg`: Media ID for background image
- `bg_size`: "original", "cover", "contain"
- `bg_overlay`: RGBA overlay
- `parallax`: Depth value (1-10, higher = more dramatic)
- `padding`: Vertical padding

### Solid Color Section
```
[section bg="rgb(246,246,246)" padding="60px"]
  [row]
    [col span="12"]
      Content
    [/col]
  [/row]
[/section]
```

- `bg`: RGB/hex color value

## Grid System

### Row
```
[row v_align="middle" h_align="center"]
  [col span="4" span__sm="12"]...[/col]
  [col span="4" span__sm="12"]...[/col]
  [col span="4" span__sm="12"]...[/col]
[/row]
```

**Parameters:**
- `v_align`: "top", "middle", "bottom" (vertical alignment)
- `h_align`: "left", "center", "right" (row horizontal alignment)
- `gap`: Space between columns

### Column
```
[col span="6" span__sm="12" span__md="10" animate="fadeInUp" visibility="hide-for-small" tooltip="Hover text"]
  Content
[/col]
```

**Parameters:**
- `span`: Grid units 1-12 (12 = full width, 6 = half, 4 = third)
- `span__sm`: Mobile override (usually "12")
- `span__md`: Tablet override
- `animate`: "fadeInUp", "fadeInLeft", "slideInDown", etc.
- `visibility`: "hide-for-small", "hide-for-medium", "show-for-small"
- `tooltip`: Hover tooltip text

### Nested Layouts
```
[col span="6" span__sm="12"]
  [row_inner]
    [col_inner span="12"]
      Nested content
    [/col_inner]
  [/row_inner]
[/col]
```

Use `[row_inner]` and `[col_inner]` for layouts nested inside columns.

## Content Blocks

### Text Block
```
[ux_text font_size="2" line_height="1.5" text_align="center"]
  <h2>Heading</h2>
  <p>Paragraph text</p>
[/ux_text]
```

**Parameters:**
- `font_size`: 1, 1.5, 2, 2.5, 3
- `font_size__sm`: Mobile font size
- `font_size__md`: Tablet font size
- `line_height`: 1.2, 1.5, 2
- `text_align`: "left", "center", "right"
- `color`: RGB, hex, or color name

### Image
```
[ux_image id="MEDIA_ID" width="25"]

[ux_image id="MEDIA_ID" image_size="original" lightbox="true"]
```

**Parameters:**
- `id`: Media library ID
- `width`: 10-100 (percentage)
- `image_size`: "original", "large", "medium", "thumbnail"
- `lightbox`: "true" for clickable zoom

### Video
```
[ux_video url="https://youtube.com/watch?v=VIDEO_ID" height="52%" depth="3"]

[ux_video url="https://vimeo.com/VIDEO_ID" height="400px"]
```

**Parameters:**
- `url`: YouTube, Vimeo, or direct video URL
- `height`: "52%" (relative) or "400px" (absolute)
- `depth`: Shadow depth (1-10)

### Featured Box
```
[featured_box img="MEDIA_ID" img_width="38"]
  <h3>Feature Title</h3>
  <p>Feature description text</p>
[/featured_box]
```

**Parameters:**
- `img`: Media ID for icon/image
- `img_width`: 20-50 (percentage)

### Button
```
[button text="Learn More" color="secondary" style="outline" radius="8" link="https://..."]

[button text="Get Started" color="primary" size="larger" radius="8" link="https://..."]
```

**Parameters:**
- `text`: Button label
- `color`: "primary" (dark blue), "secondary" (accent blue), "white"
- `style`: "filled", "outline", "link"
- `size`: "smaller", "medium", "larger"
- `radius`: "0", "4", "8" (border radius px)
- `link`: URL or internal page link

### Gap
```
[gap height="30px"]
[gap height="60px"]
```

Vertical spacing between sections.

### Divider
```
[divider align="center" width="100%" height="2px"]
```

**Parameters:**
- `align`: "left", "center", "right"
- `width`: "100%", "50%", "200px"
- `height`: Line thickness (1px, 2px, 3px)

### Reusable Block
```
[block id="22415"]
```

References a saved reusable block by WordPress post ID. Useful for repeated sections (footers, CTAs, etc.).

## Axtolab Brand Colors

Use these consistently:
- **Primary Dark Blue**: `#003952` or `rgb(0,57,82)`
- **Accent Blue**: `#009fde` or `rgb(0,159,222)`
- **White**: `rgb(255,255,255)` or `#ffffff`
- **Light Background**: `rgb(246,246,246)` or `#f6f6f6`
- **Green Accent**: `rgb(148,228,132)` or `#94e484`

## Common Page Patterns

### Hero + Feature Grid
```
[section bg="HERO_IMAGE_ID" bg_overlay="rgba(0,57,82,0.657)" padding="140px"]
  [row h_align="center"]
    [col span="8" span__sm="12"]
      [ux_text font_size="2.5" text_align="center"]<h1>Hero Title</h1>[/ux_text]
    [/col]
  [/row]
[/section]

[section bg="rgb(246,246,246)" padding="60px"]
  [row h_align="center" gap="20px"]
    [col span="4" span__sm="12" animate="fadeInUp"]
      [featured_box img="ICON_ID"]
        <h3>Feature 1</h3>
      [/featured_box]
    [/col]
    [col span="4" span__sm="12" animate="fadeInUp"]
      [featured_box img="ICON_ID"]
        <h3>Feature 2</h3>
      [/featured_box]
    [/col]
    [col span="4" span__sm="12" animate="fadeInUp"]
      [featured_box img="ICON_ID"]
        <h3>Feature 3</h3>
      [/featured_box]
    [/col]
  [/row]
[/section]
```

### CTA Section
```
[section bg="rgb(0,57,82)" padding="80px"]
  [row h_align="center"]
    [col span="6" span__sm="12"]
      [ux_text font_size="2" text_align="center" color="white"]
        <h2>Ready to Get Started?</h2>
        <p>Explore Axtolab today.</p>
      [/ux_text]
      [gap height="20px"]
      [button text="Learn More" color="secondary" style="filled" link="https://..."]
    [/col]
  [/row]
[/section]
```

### Stats/Numbers Row
```
[row h_align="center" gap="30px"]
  [col span="3" span__sm="12" text_align="center"]
    [ux_text font_size="3"]<strong>500+</strong>[/ux_text]
    <p>Customers</p>
  [/col]
  [col span="3" span__sm="12" text_align="center"]
    [ux_text font_size="3"]<strong>10M+</strong>[/ux_text]
    <p>Data Points</p>
  [/col]
  [col span="3" span__sm="12" text_align="center"]
    [ux_text font_size="3"]<strong>99.9%</strong>[/ux_text]
    <p>Uptime</p>
  [/col]
  [col span="3" span__sm="12" text_align="center"]
    [ux_text font_size="3"]<strong>24/7</strong>[/ux_text]
    <p>Support</p>
  [/col]
[/row]
```

## Image Workflow

### Selecting images from the media library
1. Call `wp_search_media` with relevant keywords
2. **Display thumbnails visually** — fetch each `thumbnail_url` and show images inline
3. Present as numbered choices with titles and dimensions
4. User picks by number, or drops a new image

### Uploading images — flexible timing, file path only

**Images can be uploaded at any point** — before drafting, during, or after the draft is in WordPress. **Never ask for base64 or image data.**

**How to upload a local image:**
1. Call `wp_find_media_file` with the filename (auto-searches ~/Downloads, ~/Desktop, ~/Documents, ~/Pictures).
2. If found, call `wp_upload_media_from_path` with the `file_path` → get a `media_id`.
3. If not found: ask *"What folder are your images in? e.g. ~/Downloads/project-images"* and retry.
4. Use the returned `media_id` in `[ux_image id="ID"]`, `[section bg="ID"]`, etc.

**Other sources:**
- URL → `wp_upload_media_from_url` (server-side, zero token cost)
- Existing media → `wp_search_media` → show thumbnails visually → user picks

## Artifact Metadata Header

Every page artifact must begin with a metadata header block so the user can instantly see what has been completed and what's still outstanding — **without needing to open WordPress**.

```
## 📋 Page Metadata
| Field | Status | Value |
|---|---|---|
| Title | ✅ | My Page Title |
| Slug | ❌ | not set |
| Author | ✅ | Jane Doe |
| Categories | ❌ | not set |
| Tags | ❌ | not set |
| Featured Image | ✅ | hero.jpg (ID: 1234) |
| Excerpt | ❌ | not set |
| Focus Keyphrase | ✅ | industrial operations |
| SEO Title | ❌ | not set |
| Meta Description | ❌ | not set |
| Schedule | ✅ | 2026-03-01 09:00 AEST |
```

Update this header **incrementally** — patch only the rows that changed using str_replace. Never delete and recreate the artifact.

---

## Page Creation Workflow

### Step 1 — Ideation (always start here)

At the start of a new page creation session, ask:

> "Would you like to go through this step-by-step (guided), or just have a conversation and I'll follow your lead (conversational)?"

**GUIDED MODE:**
1. **Purpose & structure** — "What is this page for? Who's the audience? What sections do you need?"
2. **Context gathering** — offer to:
   - Search existing pages on this site for layout patterns (`wp_find_content`)
   - Clone a similar existing page as a starting point (`wp_clone_content`)
   - Incorporate any reference material or design briefs the user wants to share
3. **Final brief** — "Any final instructions before I draft the structure? Specific sections, design requirements, things to avoid?"
4. THEN create the .md artifact. Metadata header starts mostly ❌ — that's expected.

**CONVERSATIONAL MODE:**
Have a natural back-and-forth. Let the page purpose, structure, and requirements emerge organically from conversation. Still offer to search existing pages or clone similar layouts when relevant. **Never produce a draft structure until the user explicitly signals readiness** ("ok draft it", "write it up", "go ahead").

**BOTH MODES — HARD RULES:**
- Never draft without understanding the page purpose and getting an explicit user signal
- Never front-load metadata questions (author, categories, SEO, schedule)
- Never auto-advance between phases

### Step 2 — Clone or start from scratch

**Prefer cloning**: `wp_clone_content` on a similar page. Pages are complex — duplicating structure is always faster than building from scratch.

### Step 3 — Handle images (any time)

When the user mentions or drops an image at any point: call `wp_find_media_file` with the filename. Note the `file_path` for later upload, or upload immediately if the user prefers.

### Step 4 — Draft the page layout in an artifact first

Write the complete shortcode structure as a **conversation artifact**, starting with the metadata header block. This artifact is the **user's primary source of truth and their window into the page content** — they can read, review, copy, and request changes directly from the artifact **without needing to open WordPress or request a preview**.

For images not yet uploaded, use placeholder comments: `<!-- IMAGE: hero.jpg, section background -->`.

The user should be able to review and approve:
- Page structure, section order, layout, and content
- Metadata status (slug, author, categories, tags, schedule, SEO)

**Do not call any WordPress write tools until the user has approved the artifact structure.**

**INCREMENTAL EDITS ONLY:** When updating the artifact, use str_replace to patch only the changed section. Never delete and recreate the whole artifact. The preview link (`wp_get_preview_link`) is for final visual rendering check — especially important for pages with complex layouts — but the artifact serves as the content review layer.

**After every `wp_update_content` call, patch the artifact to match the live draft.** Keep it in sync at all times.

### Step 5 — Upload images (any time — before or after draft creation)

Call `wp_find_media_file` then `wp_upload_media_from_path` for each image. Replace placeholders with real media IDs in both the artifact and the WordPress draft via `wp_update_content`.

### Step 6 — Create or update the WordPress draft

- New page: `wp_create_draft` with `content_format: "html"` (after user approves structure)
- Existing page: `wp_update_content` with the updated content

### Step 7 — Set featured image and SEO

1. `wp_set_featured_image`
2. `wp_update_yoast_metadata` — **use the full `yoast_wpseo_*` key names**:
   - `yoast_wpseo_title` — SEO title
   - `yoast_wpseo_metadesc` — meta description
   - `yoast_wpseo_focuskw` — focus keyphrase
   - `yoast_wpseo_opengraph-title`, `yoast_wpseo_opengraph-description`
   - `yoast_wpseo_twitter-title`, `yoast_wpseo_twitter-description`

### Step 8 — Preview (mandatory for pages)

Call `wp_get_preview_link` and share the signed URL. Pages are complex visual layouts — **always preview before publishing**.

### Step 9 — Iterate

User reviews in browser → `wp_update_content` → update artifact → share new preview link.

### Step 10 — Schedule or publish

Before calling `wp_publish_content`, **always ask**:

> "Should I publish this page immediately, or would you like to schedule it for a specific date and time?"

- **Publish immediately**: call `wp_publish_content` without a `date` param.
- **Schedule**: ask for date, time, and timezone → convert to ISO 8601 UTC → pass as `date` param (e.g. `2026-03-01T23:00:00Z`).

The confirmation token flow will engage automatically. The user should never need to open WordPress.

## Image Workflow — Quick Reference

| Scenario | Action |
|---|---|
| User drops/mentions a local file | `wp_find_media_file` (filename) → `wp_upload_media_from_path` (file_path) |
| File not found automatically | Ask for folder → retry `wp_find_media_file` with `folder` |
| Images before drafting | Upload first → use real `media_id` in artifact from the start |
| Images after draft is created | Upload → `wp_update_content` + update artifact |
| Image at a URL | `wp_upload_media_from_url` (any time, zero tokens) |
| Choose from media library | `wp_search_media` → show thumbnails visually → user picks |

## Pro Tips

- **Artifact-first**: Always draft the full page structure as a conversation artifact before touching WordPress. Pages are too complex to build without user review first.
- **Clone existing pages**: `wp_clone_content` is your best friend. Pages are too complex to build from scratch.
- **Responsive first**: Always `span__sm="12"` on all columns.
- **Reusable blocks**: Reference `[block id="X"]` for repeated elements (CTAs, footers).
- **Section padding**: Hero `padding="140px"`, feature sections `padding="60px"`, content `padding="40px"`.
- **Video backgrounds**: Always provide both `video_mp4` and `video_webm`.
- **Show don't tell**: Display thumbnails visually, never just list metadata.
- **Preview is mandatory**: Complex page layouts MUST be previewed before publishing.

## Content Format Setting

Always use:
```
content_format: "html"
```

This ensures shortcodes are processed correctly and not converted to markdown.
