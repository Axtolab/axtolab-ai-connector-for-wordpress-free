# Blog Post Format Guide (Flatsome Theme)

## Overview
This guide covers blog posts on sites using the **Flatsome theme** with a **prose-first** approach. Most content is plain text paragraphs. Flatsome shortcodes are used sparingly for specific layout elements (hero images, spacing, author attribution, and column sections).

**Critical:** Use `content_format: "html"` when content contains shortcodes.

## Content Structure Pattern

```
[Hero Image - optional]
  ↓
[Gap - optional spacing]
  ↓
[Body Paragraphs - plain text, no wrapping shortcode]
  ↓
[Section breaks with gaps]
  ↓
[Multi-column layouts - optional]
  ↓
[Author attribution - if needed]
```

## Core Rules

1. **Body paragraphs are plain text** — Do NOT wrap in shortcodes
2. **Images at top of post** — Use `[ux_image]` shortcode only for hero/feature images
3. **Spacing between sections** — Use `[gap]` shortcode
4. **Section headings** — Plain text (h2, h3, etc.), no shortcode wrapping
5. **Multi-column content** — Use `[row]` and `[col]` for side-by-side layouts only
6. **Author box at end** — Use `[testimonial]` shortcode

## Commonly Used Shortcodes

### Hero Image
```
[ux_image id="12345" image_size="original"]
```
- `id`: WordPress media library ID (upload first, get ID, then use here)
- `image_size`: "original", "large", "medium", "thumbnail"

### Gap (Spacing)
```
[gap]
[gap height="15px"]
[gap height="30px"]
```

### Centered Text Section
```
[ux_text text_align="center"]
  Your centered content here
[/ux_text]
```

### Multi-Column Layout
```
[row h_align="center"]
  [col span="4" span__sm="12"]
    Left column content
  [/col]
  [col span="4" span__sm="12"]
    Right column content
  [/col]
[/row]
```
- `span`: Grid units (12 = full width, 6 = half, 4 = third)
- `span__sm`: Always "12" for mobile single-column

### Author Attribution
```
[testimonial image="4567" stars="0"]
By Your Name
Your Title / Company
[/testimonial]
```

## Minimal Template

```
[ux_image id="HERO_IMAGE_ID" image_size="original"]

[gap height="30px"]

Opening paragraph. Write naturally without shortcodes.

Second paragraph. Keep paragraphs separated by blank lines.

[gap height="20px"]

## Section Heading

Body paragraphs. Plain text, no wrapping shortcodes.

[gap height="30px"]

[row h_align="center"]
  [col span="6" span__sm="12"]
    First column content
  [/col]
  [col span="6" span__sm="12"]
    Second column content
  [/col]
[/row]

[gap height="40px"]

[testimonial image="AUTHOR_PHOTO_ID" stars="0"]
By Jane Doe
Product Manager, Axtolab
[/testimonial]
```

---

## Artifact Metadata Header

Every post artifact must begin with a metadata header block so the user can instantly see what has been completed and what's still outstanding — **without needing to open WordPress**.

```
## 📋 Post Metadata
| Field | Status | Value |
|---|---|---|
| Title | ✅ | My Post Title |
| Slug | ❌ | not set |
| Author | ✅ | Jane Doe |
| Categories | ✅ | AI, Operations |
| Tags | ❌ | not set |
| Featured Image | ✅ | hero.jpg (ID: 1234) |
| Excerpt | ❌ | not set |
| Focus Keyphrase | ✅ | industrial AI platform |
| SEO Title | ❌ | not set |
| Meta Description | ❌ | not set |
| Schedule | ✅ | 2026-03-01 09:00 AEST |
```

Update this header **incrementally** — patch only the rows that changed using str_replace. Never delete and recreate the artifact.

---

## Content Creation Workflow

### Step 1 — Ideation (always start here)

At the start of a new post creation session, ask:

> "Would you like to go through this step-by-step (guided), or just have a conversation and I'll follow your lead (conversational)?"

**GUIDED MODE:**
1. **Topic & angle** — "What's the article about? Core message? Audience?"
2. **Context gathering** — offer to:
   - Search existing articles on this site about the same topic (`wp_find_content`)
   - Pull the chosen author's recent posts to study their voice (`wp_find_content` by author → `wp_get_content`)
   - Incorporate any reference material the user wants to share
3. **Final brief** — "Any final instructions before I write the first draft? Tone, length, structure, things to avoid?"
4. THEN create the .md artifact with the first draft. Metadata header starts mostly ❌ — that's expected.

**CONVERSATIONAL MODE:**
Have a natural back-and-forth. Let the topic, context, and direction emerge organically. Still offer to search existing site articles or study the author's voice when relevant. **Never produce a draft until the user explicitly signals readiness** ("ok write it", "draft it", "go ahead").

**BOTH MODES — HARD RULES:**
- Never draft anything without a clear topic and user signal
- Never front-load metadata questions (author, categories, SEO, schedule) — these come in the packaging phase
- Never auto-advance between phases

---

### Step 2 — Handle images as soon as they arrive

**Images can arrive at any point** — at the start, mid-draft, or at the end. The preferred workflow is **file path, never base64**:

1. When the user mentions or drops an image (e.g. "hero.jpg"), call `wp_find_media_file` with the filename. It searches ~/Downloads, ~/Desktop, ~/Documents, ~/Pictures automatically.
2. If found, note the returned `file_path` — pass it to `wp_upload_media_from_path` when uploading.
3. If not found, ask: *"Could you share the folder where your images are stored? e.g. ~/Downloads/project-images"* — then retry with that folder.
4. Confirm to the user: *"Found your image at [path]. I'll upload it to WordPress when we're ready."*

**The user sees all images in the WordPress preview link anyway — there's no need to display or transfer image bytes in the conversation.**

**Image sources:**
- **Dropped/mentioned file** → `wp_find_media_file` → note path → upload later with `wp_upload_media_from_path`
- **URL** → `wp_upload_media_from_url` (server-side download, zero token cost)
- **Existing media** → `wp_search_media`, show thumbnails visually so user can pick

---

### Step 3 — Search and display existing media

When searching for images from the library:
1. Call `wp_search_media` with relevant keywords
2. **Display thumbnails visually** — fetch each `thumbnail_url` and show images inline so the user can see options
3. Present as numbered choices with titles and dimensions
4. User picks by number, or drops a new image

---

### Step 4 — Draft the content in an artifact first

Write the complete post HTML (with shortcodes) as a **conversation artifact**, starting with the metadata header block. This artifact is the **user's primary source of truth and their window into the post content** — they can read, review, copy, and request changes directly from the artifact **without needing to open WordPress or request a preview**.

For images not yet uploaded, use a placeholder comment: `<!-- IMAGE: hero.jpg, hero section -->`.

The user should be able to review and approve:
- Title, structure, wording, section order, and image placement
- Metadata status (slug, author, categories, tags, schedule, SEO)

**Iterate on the artifact until the user approves the structure. Do not call `wp_create_draft` until the user has seen and approved the content.**

**INCREMENTAL EDITS ONLY:** When updating the artifact, use str_replace to patch only the changed section. Never delete and recreate the whole artifact. The preview link (`wp_get_preview_link`) is for final visual rendering check, not for reviewing post content — the artifact serves that purpose.

**After every `wp_update_content` call, patch the artifact to match what is now in WordPress.** Keep it in sync at all times.

---

### Step 5 — Upload images (when user is ready)

Once the user is happy with the draft and ready to proceed to WordPress:

1. For each image, call `wp_upload_media_from_path` with the `file_path` from `wp_find_media_file` (or a user-provided path). Apply alt text, caption, title at this point.
2. Replace `[ux_image id="TBD"]` placeholders in the content with the real returned media IDs.
3. Find authors: `wp_list_authors`
4. Find/create categories: `wp_list_terms` / `wp_create_term`

---

### Step 6 — Create the WordPress draft

Call `wp_create_draft` with:
- `title`, `content` (final HTML with real media IDs), `excerpt`
- `author`, `terms`, `content_format: "html"`
- `status: "draft"` (automatic)

---

### Step 7 — Set featured image and SEO

1. `wp_set_featured_image` with chosen media ID
2. `wp_update_yoast_metadata` — **use the full `yoast_wpseo_*` key names** (not short aliases):
   - `yoast_wpseo_title` — SEO title shown in search results
   - `yoast_wpseo_metadesc` — meta description
   - `yoast_wpseo_focuskw` — focus keyphrase
   - `yoast_wpseo_opengraph-title`, `yoast_wpseo_opengraph-description` — Open Graph
   - `yoast_wpseo_twitter-title`, `yoast_wpseo_twitter-description` — Twitter/X

   Example:
   ```json
   {
     "yoast_meta": {
       "yoast_wpseo_title": "Industrial AI Platform | Axtolab",
       "yoast_wpseo_metadesc": "Axtolab helps industrial teams deploy AI-powered operations at scale.",
       "yoast_wpseo_focuskw": "industrial AI platform"
     }
   }
   ```

---

### Step 8 — Preview

Call `wp_get_preview_link` and **share the signed URL** with the user. This is the final check — the user sees the real rendered post before publishing. Always do this step.

---

### Step 9 — Iterate if needed

User reviews preview and requests changes → `wp_update_content` → share updated preview link.

---

### Step 10 — Schedule or publish

Before calling `wp_publish_content`, **always ask**:

> "Should I publish this immediately, or would you like to schedule it for a specific date and time?"

- **Publish immediately**: call `wp_publish_content` without a `date` param.
- **Schedule**: ask for date, time, and timezone → convert to ISO 8601 UTC → pass as `date` param (e.g. `2026-03-01T23:00:00Z`).

The confirmation token flow will engage automatically.

---

## Image Workflow — Quick Reference

| Scenario | Action |
|---|---|
| User drops/mentions an image file | `wp_find_media_file` (filename) → note `file_path` → upload later with `wp_upload_media_from_path` |
| File not found automatically | Ask: "What folder are your images in? e.g. ~/Downloads/project" → retry `wp_find_media_file` with `folder` |
| User says "add images later" | Write draft with `[ux_image id="TBD"]` placeholders → find + upload when ready → patch content |
| Image at a URL | `wp_upload_media_from_url` (zero token cost, any time) |
| Choose from media library | `wp_search_media` → show thumbnails visually → user picks → use media ID |

---

## Pro Tips

- **Artifact-first**: Always compose the full post in a chat artifact before touching WordPress. It's far faster to iterate on a draft in conversation than to keep patching a WordPress entry.
- **No base64**: Never pass image bytes through conversation. Use `wp_find_media_file` + `wp_upload_media_from_path`. The user sees all images in the WordPress preview anyway.
- **Don't over-shortcode**: Plain text paragraphs are the norm. Shortcodes only for layout.
- **Responsive**: Always `span__sm="12"` on columns.
- **Spacing rhythm**: Use gaps consistently (15px, 20px, 30px).
- **Show don't tell**: Display image thumbnails visually, never just list URLs.
- **Preview before publish**: Always share the preview link. No exceptions.

## Content Format Setting

Always use:
```
content_format: "html"
```

This ensures shortcodes are processed correctly and not converted to markdown.
