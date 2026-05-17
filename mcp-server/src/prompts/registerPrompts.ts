import { fileURLToPath } from "url";
import path from "path";
import fs from "fs";
import { z } from "zod";

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// Load guide files as strings
const axtolabPostGuide = fs.readFileSync(
  path.resolve(__dirname, "../guides/post-guide.md"),
  "utf-8"
);
const axtolabPageGuide = fs.readFileSync(
  path.resolve(__dirname, "../guides/page-guide.md"),
  "utf-8"
);
const genericGuide = fs.readFileSync(
  path.resolve(__dirname, "../guides/generic-guide.md"),
  "utf-8"
);

export function registerPrompts(server: any): void {
  // Register create-content prompt
  server.prompt(
    "create-content",
    "WordPress content creation guide",
    {
      site: z
        .enum(["axtolab", "generic"])
        .optional()
        .describe("Website type: 'axtolab' or 'generic'"),
      content_type: z
        .enum(["post", "page"])
        .optional()
        .describe("Type of content: 'post' or 'page'"),
    },
    async (args: Record<string, string>) => {
      const site = args.site || "generic";
      const contentType = args.content_type;

      let guideContent = genericGuide;

      if (site === "axtolab") {
        if (contentType === "post") {
          guideContent = axtolabPostGuide;
        } else if (contentType === "page") {
          guideContent = axtolabPageGuide;
        } else {
          // If no content_type specified for axtolab, combine both
          guideContent = axtolabPostGuide + "\n\n---\n\n" + axtolabPageGuide;
        }
      }

      const contextMessage = `Use the following WordPress content formatting guide when creating content. Follow these patterns exactly.

CRITICAL WORKFLOW RULES — THESE ARE MANDATORY, NOT OPTIONAL:

1. ██ ARTIFACT IS THE ONLY WORKING SPACE ██
   ALL work happens in the conversation artifact. Content edits, image uploads, metadata, SEO — everything is done and iterated in the artifact FIRST.
   The artifact is the user's source of truth. They read it, review it, and request changes directly from it — no WordPress login needed.
   wp_create_draft is called ONCE, when the user explicitly confirms the artifact is complete and ready to push to WordPress.
   Do NOT call wp_create_draft early. "Start", "go ahead", or "yes" means draft the artifact — not create the WordPress draft.

2. IDEATION FIRST — NOT A FORM:
   At the start of a session, ask: "Would you like to go through this step-by-step (guided), or just have a conversation and I'll follow your lead (conversational)?"
   GUIDED: explicit phases — topic/angle, context gathering (wp_find_content, author voice study), final brief, THEN draft.
   CONVERSATIONAL: natural back-and-forth to organically shape the topic, context, and direction — still offer to search site articles / study author voice — still NEVER draft until user signals readiness.
   In BOTH modes: never produce a draft without a clear topic and explicit user signal ("ok write it", "draft it", "go ahead").
   NEVER front-load metadata questions (author, categories, SEO, schedule) before the content direction is agreed.
   Metadata comes in Phase 3, after the user is happy with the article.

3. METADATA HEADER IN EVERY ARTIFACT:
   Every artifact starts with a ## 📋 Post/Page Metadata table showing ✅/❌ status for: title, slug, author, categories, tags, featured image, excerpt, focus keyphrase, SEO title, meta description, schedule.
   Update the header incrementally (str_replace, patch only changed rows). Never delete and recreate the artifact.

4. IMAGE UPLOADS — flexible timing, NEVER base64:
   Images can be uploaded at ANY POINT.
   HOW: Call wp_find_media_file with the filename → if found, call wp_upload_media_from_path → get media_id → update artifact with real ID.
   If NOT found: check the diagnostic info returned (searched dirs, closest matches). Ask: "Could you share the folder? e.g. ~/Downloads/project-images".
   Placeholder in artifact when not yet uploaded: <!-- IMAGE: hero.jpg, hero section -->
   Other sources: URL → wp_upload_media_from_url | Existing → wp_search_media → show thumbnails visually.
   NEVER ask for base64. NEVER pass image bytes through conversation.

5. AFTER wp_create_draft — artifact remains source of truth:
   If the user requests further changes after the draft is in WordPress, update the artifact first, then ASK: "Should I also update the WordPress draft?" before calling wp_update_content.

6. PREVIEW BEFORE PUBLISH — MANDATORY:
   Always call wp_get_preview_link after pushing to WordPress. Share the signed URL for a final visual rendering check.

7. SCHEDULE OR PUBLISH — ALWAYS ASK:
   Before calling wp_publish_content, always ask: "Should I publish immediately, or schedule for a specific date and time?"
   Schedule: convert to ISO 8601 UTC and pass as the date param.

8. NEVER OPEN WORDPRESS:
   The user should never need to open the WordPress admin. Everything happens in this conversation.

9. CONFIRMATION FLOW:
   Publish and trash actions require a confirmation token — handled automatically.

---
${guideContent}
---`;

      return {
        description: "WordPress content creation guide",
        messages: [
          {
            role: "user",
            content: {
              type: "text",
              text: contextMessage,
            },
          },
        ],
      };
    }
  );

  // Register resource for axtolab-post guide
  server.resource(
    "wordpress://guides/axtolab-post",
    "wordpress://guides/axtolab-post",
    { description: "Axtolab blog post format guide", mimeType: "text/markdown" },
    async () => ({
      contents: [
        {
          uri: "wordpress://guides/axtolab-post",
          mimeType: "text/markdown",
          text: axtolabPostGuide,
        },
      ],
    })
  );

  // Register resource for axtolab-page guide
  server.resource(
    "wordpress://guides/axtolab-page",
    "wordpress://guides/axtolab-page",
    {
      description: "Axtolab page format guide",
      mimeType: "text/markdown",
    },
    async () => ({
      contents: [
        {
          uri: "wordpress://guides/axtolab-page",
          mimeType: "text/markdown",
          text: axtolabPageGuide,
        },
      ],
    })
  );

  // Register resource for generic guide
  server.resource(
    "wordpress://guides/generic",
    "wordpress://guides/generic",
    {
      description: "Generic WordPress content format guide",
      mimeType: "text/markdown",
    },
    async () => ({
      contents: [
        {
          uri: "wordpress://guides/generic",
          mimeType: "text/markdown",
          text: genericGuide,
        },
      ],
    })
  );
}
