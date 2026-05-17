import type { ServerConfig } from "../config.js";
import { ToolError } from "../utils/errors.js";

const DEFAULT_PATCH_FIELDS = new Set([
  "title",
  "content",
  "excerpt",
  "slug",
  "featured_media",
  "author",
  "date",
]);

const DEFAULT_YOAST_META_FIELDS = new Set([
  "yoast_wpseo_title",
  "yoast_wpseo_metadesc",
  "yoast_wpseo_canonical",
  "yoast_wpseo_focuskw",
  "yoast_wpseo_opengraph-title",
  "yoast_wpseo_opengraph-description",
  "yoast_wpseo_twitter-title",
  "yoast_wpseo_twitter-description",
]);

export class PolicyService {
  public constructor(private readonly config: ServerConfig) {}

  public assertAllowedContentType(contentType: string): void {
    if (this.config.allowedContentTypes.length === 0) return;
    if (!this.config.allowedContentTypes.includes(contentType)) {
      throw new ToolError("DISALLOWED_CONTENT_TYPE", `Content type is not allowed: ${contentType}`);
    }
  }

  public assertAllowedTaxonomy(taxonomy: string): void {
    if (this.config.allowedTaxonomies.length === 0) return;
    if (!this.config.allowedTaxonomies.includes(taxonomy)) {
      throw new ToolError("DISALLOWED_TAXONOMY", `Taxonomy is not allowed: ${taxonomy}`);
    }
  }

  public assertAllowedAuthor(authorId: number): void {
    if (this.config.allowedAuthors.length === 0) {
      return;
    }

    if (!this.config.allowedAuthors.includes(String(authorId))) {
      throw new ToolError("DISALLOWED_AUTHOR", `Author is not allowlisted: ${authorId}`);
    }
  }

  public assertPatchFields(patch: Record<string, unknown>): void {
    for (const field of Object.keys(patch)) {
      if (!DEFAULT_PATCH_FIELDS.has(field)) {
        throw new ToolError("DISALLOWED_PATCH_FIELD", `Patch field is not allowed: ${field}`);
      }
    }
  }

  public assertYoastPath(path: string): void {
    if (this.config.allowedYoastPaths.length === 0) return;
    if (!this.config.allowedYoastPaths.some((allowed) => path.startsWith(allowed))) {
      throw new ToolError("DISALLOWED_YOAST_PATH", `Yoast path is not allowed: ${path}`);
    }
  }

  public assertYoastMetaKeys(meta: Record<string, unknown>): void {
    for (const key of Object.keys(meta)) {
      if (!DEFAULT_YOAST_META_FIELDS.has(key)) {
        throw new ToolError("DISALLOWED_YOAST_META_KEY", `Yoast meta key is not allowed: ${key}`);
      }
    }
  }

  public assertMediaPolicy(mimeType: string, _sizeBytes: number, _altText?: string): void {
    if (this.config.allowedMediaTypes.length > 0 && !this.config.allowedMediaTypes.includes(mimeType)) {
      throw new ToolError("DISALLOWED_MEDIA_MIME", `MIME type is not allowed: ${mimeType}`);
    }
  }
}
