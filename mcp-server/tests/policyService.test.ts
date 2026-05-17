import { describe, expect, it } from "vitest";
import { PolicyService } from "../src/services/policyService.js";
import type { ServerConfig } from "../src/config.js";

const config: ServerConfig = {
  wpPluginBaseUrl: "https://example.com/wp-json/wp-mcp-gateway/v1",
  username: "test",
  appPassword: "app-pass",
  allowedContentTypes: ["post", "page", "featured_item"],
  allowedTaxonomies: ["category", "post_tag", "featured_item_category", "featured_item_tag"],
  allowedAuthors: ["1", "2"],
  allowedYoastPaths: ["/yoast/analysis", "/yoast/metadata", "/yoast/head"],
  allowedMediaTypes: ["image/jpeg", "image/png"],
  rateLimitTokens: 60,
  rateLimitRefillRate: 1,
  httpTimeout: 30_000,
};

describe("PolicyService", () => {
  const policy = new PolicyService(config);

  it("allows configured content type", () => {
    expect(() => policy.assertAllowedContentType("post")).not.toThrow();
  });

  it("rejects disallowed content type", () => {
    expect(() => policy.assertAllowedContentType("job_listing")).toThrowError(/not allowed/);
  });

  it("rejects unknown patch fields", () => {
    expect(() => policy.assertPatchFields({ title: "ok", sticky: true })).toThrowError(/not allowed/);
  });

  it("rejects disallowed taxonomy", () => {
    expect(() => policy.assertAllowedTaxonomy("job-categories")).toThrowError(/not allowed/);
  });

  it("rejects disallowed author", () => {
    expect(() => policy.assertAllowedAuthor(99)).toThrowError(/allowlisted/);
  });

  it("rejects disallowed media MIME type", () => {
    expect(() => policy.assertMediaPolicy("video/mp4", 100)).toThrowError(/not allowed/);
    expect(() => policy.assertMediaPolicy("image/jpeg", 100)).not.toThrow();
  });

  it("accepts allowed yoast path", () => {
    expect(() => policy.assertYoastPath("/yoast/analysis")).not.toThrow();
  });

  it("rejects disallowed yoast path", () => {
    expect(() => policy.assertYoastPath("/yoast/indexing/prepare")).toThrowError(/not allowed/);
  });
});
