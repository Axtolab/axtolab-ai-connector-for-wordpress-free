# Generic WordPress Content Format Guide

## Overview
For standard WordPress sites without custom shortcodes, use plain HTML or Gutenberg block markup. No special theme-specific shortcuts — just semantic HTML.

## Content Formats Supported

### Option 1: HTML (Recommended for Simplicity)
```html
<p>Regular paragraph text.</p>

<h2>Heading</h2>

<p>More paragraph text with <strong>bold</strong> and <em>italic</em>.</p>

<ul>
  <li>List item 1</li>
  <li>List item 2</li>
</ul>

<img src="https://..." alt="Image description">

<a href="https://...">Link text</a>

<blockquote>Quote text</blockquote>
```

Use `content_format: "html"` with this approach.

### Option 2: Markdown (Auto-Converted to HTML)
```markdown
# Heading 1

## Heading 2

Regular paragraph text with **bold** and *italic*.

- List item 1
- List item 2

[Link text](https://...)

> Blockquote text
```

Use `content_format: "markdown"` with this approach.

### Option 3: Gutenberg Block Markup
```html
<!-- wp:paragraph -->
<p>Paragraph content</p>
<!-- /wp:paragraph -->

<!-- wp:heading {"level":2} -->
<h2>Heading</h2>
<!-- /wp:heading -->

<!-- wp:image {"id":123,"sizeSlug":"large"} -->
<figure class="wp-block-image size-large">
  <img src="https://..." alt=""/>
</figure>
<!-- /wp:image -->

<!-- wp:list -->
<ul>
  <li>Item 1</li>
  <li>Item 2</li>
</ul>
<!-- /wp:list -->
```

Use `content_format: "html"` with this approach.

## Common HTML Elements

### Headings
```html
<h1>Page Title</h1>
<h2>Section Heading</h2>
<h3>Subsection</h3>
```

### Text Formatting
```html
<p>Regular paragraph.</p>
<strong>Bold text</strong>
<em>Italic text</em>
<code>Code snippet</code>
```

### Lists
```html
<ul>
  <li>Unordered item 1</li>
  <li>Unordered item 2</li>
</ul>

<ol>
  <li>Ordered item 1</li>
  <li>Ordered item 2</li>
</ol>
```

### Images
```html
<img src="https://example.com/image.jpg" alt="Image description">

<!-- Or with figure element -->
<figure>
  <img src="https://example.com/image.jpg" alt="">
  <figcaption>Optional image caption</figcaption>
</figure>
```

### Links
```html
<a href="https://example.com">Link text</a>
<a href="https://example.com" target="_blank">External link</a>
```

### Blockquote
```html
<blockquote>
  <p>Quote text here</p>
  <cite>— Author Name</cite>
</blockquote>
```

### Separator/Divider
```html
<hr>
```

## Simple Content Template

```html
<h1>Post Title</h1>

<p>Opening paragraph introducing the topic.</p>

<h2>First Section</h2>

<p>Section content goes here. Use natural paragraphs.</p>

<img src="IMAGE_URL" alt="Relevant image">

<h2>Second Section</h2>

<p>More content.</p>

<ul>
  <li>Key point 1</li>
  <li>Key point 2</li>
  <li>Key point 3</li>
</ul>

<h2>Conclusion</h2>

<p>Closing thoughts.</p>
```

---

## Content Creation Workflow

### Artifact Metadata Header

Every content artifact MUST begin with this metadata dashboard block, separated from the content body by `---`. Update it incrementally (str_replace on changed fields only) — never recreate the whole artifact.

```markdown
## 📋 Post Metadata
| Field | Status | Value |
|---|---|---|
| **Title** | ✅ | Your Post Title |
| **Slug** | ❌ | not set |
| **Author** | ❌ | not set |
| **Categories** | ❌ | not set |
| **Tags** | ❌ | not set |
| **Featured Image** | ❌ | not set |
| **Excerpt** | ❌ | not set |
| **Yoast Focus KW** | ❌ | not set |
| **Yoast SEO Title** | ❌ | not set |
| **Yoast Meta Desc** | ❌ | not set |
| **Schedule** | ❌ | not decided |

---

[content body below]
```

Use ✅ when a field is confirmed, ❌ when still missing.

---

### Step 1 — Ideation (always start here)

**Ask the user first:**
> "Would you like to go through this step-by-step (guided), or just have a conversation and I'll follow your lead (conversational)?"

**GUIDED MODE:**
1. **Topic & angle** — "What's this about? Core message? Audience?"
2. **Context gathering** — offer to:
   - Search existing articles on this site about the same topic (`wp_find_content`)
   - Pull the chosen author's recent posts to study their voice (`wp_find_content` by author → `wp_get_content`)
   - Incorporate any reference material the user wants to share
3. **Final brief** — "Any final instructions before I write the first draft? Tone, length, structure, things to avoid?"
4. THEN create the .md artifact. Metadata header starts mostly ❌ — that's expected.

**CONVERSATIONAL MODE:**
Have a natural back-and-forth. Let the topic, context, and direction emerge organically. Still offer to search existing site articles or study the author's voice when relevant. **Never produce a draft until the user explicitly signals readiness** ("ok write it", "draft it", "go ahead").

**BOTH MODES — HARD RULES:**
- Never draft without a clear topic and explicit user signal
- Never front-load metadata questions (author, categories, SEO, schedule)
- Never auto-advance between phases

---

### Images — Upload at Any Time (Flexible)

**Images can be uploaded at any point** — before writing, mid-draft, or after the draft is in WordPress. The user decides the timing. Always use file paths; never ask for base64 or image data in conversation.

**How to upload a local image (preferred path):**
1. Call `wp_find_media_file` with the filename (auto-searches ~/Downloads, ~/Desktop, ~/Documents, ~/Pictures).
2. If found, call `wp_upload_media_from_path` with the returned `file_path` → get a WordPress `media_id`.
3. If not found: ask *"What folder are your images in? e.g. ~/Downloads/project-images"* then retry with `folder`.

**Other sources:**
- **URL** → `wp_upload_media_from_url` (server-side, zero token cost)
- **Existing media** → `wp_search_media` → display thumbnails visually inline → user picks → use `media_id`

**The user sees all images in the WordPress preview link — there is no need to display image bytes in conversation.**

---

### Step 2 — Draft content in an artifact

Write the complete HTML as a **chat artifact**. Start with the metadata header block (see above), then `---`, then the content body. For images not yet uploaded, use a placeholder comment: `<!-- IMAGE: hero.jpg, hero position -->`.

Show the draft. Iterate freely — wording, structure, sections — all in conversation. This is free and fast.

**Incremental edits only**: When updating the artifact after feedback, use str_replace to patch only the changed section. Never delete and recreate the whole artifact — it's slow and loses context.

---

### Step 3 — User approves structure → create WordPress draft

When the user is happy with the structure (images don't need to be uploaded yet), call `wp_create_draft`. Images and copy can still be refined afterwards via `wp_update_content`.

Find authors: `wp_list_authors`. Find/create categories: `wp_list_terms` / `wp_create_term`.

---

### Step 4 — Upload images and patch them in (any time)

For each image: `wp_find_media_file` → `wp_upload_media_from_path` → get `media_id`. Then `wp_update_content` to replace placeholders with real IDs. Do this before or after draft creation — the user's preference.

---

### Step 5 — Set featured image and SEO

1. `wp_set_featured_image` with the chosen `media_id`
2. `wp_update_yoast_metadata` — use full key names:
   - `yoast_wpseo_title`, `yoast_wpseo_metadesc`, `yoast_wpseo_focuskw`

---

### Step 6 — Preview

Call `wp_get_preview_link` and share the signed URL. The user sees the fully rendered post with all images. Always do this step.

---

### Step 7 — Iterate

User reviews preview → `wp_update_content` → new preview link. Repeat until approved.

---

### Step 8 — Schedule or publish

**Always ask before publishing:**
> "Should I publish immediately, or would you like to schedule this for a specific date and time? (e.g. next Monday at 9am — please include your timezone)"

- **Publish now:** `wp_publish_content` with no `date` (confirmation token flow engages automatically).
- **Schedule:** Convert user's date/time to ISO 8601 UTC and pass in `date` parameter (e.g. `2026-03-01T23:00:00Z` for 9am AEST/UTC+10).

---

## Image Workflow — Quick Reference

| Scenario | Action |
|---|---|
| User drops/mentions a local file | `wp_find_media_file` (filename) → `wp_upload_media_from_path` (file_path) |
| File not found automatically | Ask for folder → retry `wp_find_media_file` with `folder` |
| Image at a URL | `wp_upload_media_from_url` (any time, zero tokens) |
| Choose from existing media library | `wp_search_media` → display thumbnails inline → user picks |
| Upload before drafting | Upload first → use real `media_id` in artifact immediately |
| Upload after draft is created | Upload → `wp_update_content` to patch in real IDs |

---

## Content Format Selection

- **`content_format: "html"`** — Use for HTML, Gutenberg blocks, or mixed content
- **`content_format: "markdown"`** — Use for markdown-formatted content (auto-converts to HTML)
- **`content_format: "auto"`** — WordPress auto-detects format (usually works fine)

## Pro Tips

- **Artifact-first**: Compose the full post in a chat artifact before creating the WordPress draft. Far faster to iterate.
- **Keep it semantic**: Use proper HTML tags (h1-h6, p, ul, ol, blockquote)
- **Alt text required**: Always include `alt` on images for accessibility
- **Link target**: `target="_blank"` for external links only
- **No inline styles**: Let WordPress/theme handle styling via CSS
- **Short paragraphs**: 2-4 sentences per paragraph
- **Show don't tell**: Display image thumbnails visually, never just list metadata
- **Preview before publish**: Always share the preview link

## When to Use Custom Theme Guides

If the WordPress site uses:
- **Axtolab + Flatsome theme** → Use `axtolab-post-guide.md` or `axtolab-page-guide.md`
- **Custom shortcodes** → Ask the user for theme/plugin documentation
- **Gutenberg patterns** → This generic guide works fine

Otherwise, this generic HTML approach works for any WordPress site.
