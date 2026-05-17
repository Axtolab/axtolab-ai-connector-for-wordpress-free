import MarkdownIt from "markdown-it";

const md = new MarkdownIt({
  html: true,        // Allow HTML passthrough (content may already contain HTML)
  linkify: true,     // Auto-convert URLs to links
  typographer: true, // Smart quotes, dashes
});

export type ContentFormat = "html" | "markdown" | "auto";

/**
 * Detect whether content is likely Markdown vs HTML.
 * Heuristic: if it contains block-level HTML tags, treat as HTML.
 */
function looksLikeHtml(content: string): boolean {
  // Check for common block-level HTML elements
  return /<(div|p|h[1-6]|ul|ol|table|figure|section|article|blockquote)\b/i.test(content);
}

/**
 * Convert content to HTML based on the specified format.
 * - "html": pass through unchanged
 * - "markdown": always convert
 * - "auto" (default): detect and convert if it looks like markdown
 */
export function toHtml(content: string, format: ContentFormat = "auto"): string {
  if (!content || !content.trim()) {
    return content;
  }

  if (format === "html") {
    return content;
  }

  if (format === "markdown") {
    return md.render(content);
  }

  // Auto mode: if it already looks like HTML, leave it alone
  if (looksLikeHtml(content)) {
    return content;
  }

  // Otherwise, convert from markdown
  return md.render(content);
}
