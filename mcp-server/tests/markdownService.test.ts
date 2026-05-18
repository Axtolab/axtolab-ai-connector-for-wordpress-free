import { describe, expect, it } from "vitest";
import { toHtml } from "../src/services/markdownService.js";

describe("markdownService", () => {
  it("converts markdown headings to HTML", () => {
    const result = toHtml("## Hello World", "markdown");
    expect(result).toContain("<h2>");
    expect(result).toContain("Hello World");
  });

  it("converts markdown bold and italic", () => {
    const result = toHtml("**bold** and *italic*", "markdown");
    expect(result).toContain("<strong>bold</strong>");
    expect(result).toContain("<em>italic</em>");
  });

  it("passes through HTML in html mode", () => {
    const html = "<h1>Already HTML</h1><p>Content</p>";
    expect(toHtml(html, "html")).toBe(html);
  });

  it("auto-detects HTML and passes through", () => {
    const html = "<div class=\"container\"><p>Already HTML</p></div>";
    expect(toHtml(html, "auto")).toBe(html);
  });

  it("auto-detects markdown and converts", () => {
    const md = "# Title\n\nSome **bold** text.";
    const result = toHtml(md, "auto");
    expect(result).toContain("<h1>");
    expect(result).toContain("<strong>bold</strong>");
  });

  it("handles empty content gracefully", () => {
    expect(toHtml("", "auto")).toBe("");
    expect(toHtml("", "markdown")).toBe("");
  });

  it("converts markdown links", () => {
    const result = toHtml("[Click here](https://example.com)", "markdown");
    expect(result).toContain('href="https://example.com"');
    expect(result).toContain("Click here");
  });

  it("converts markdown lists", () => {
    const result = toHtml("- Item 1\n- Item 2\n- Item 3", "markdown");
    expect(result).toContain("<ul>");
    expect(result).toContain("<li>");
  });
});
