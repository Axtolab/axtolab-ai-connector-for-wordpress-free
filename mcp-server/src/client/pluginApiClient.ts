import type { ServerConfig } from "../config.js";
import type {
  ApiEnvelope,
  AuthorRecord,
  ContentRecord,
  MediaRecord,
  PreviewLinkRecord,
  RevisionRecord,
  TermRecord,
  YoastAnalysisRecord,
} from "../types/contracts.js";
import { ToolError } from "../utils/errors.js";

interface RequestOptions {
  query?: Record<string, string | number | boolean | undefined | null>;
  body?: unknown;
}

export class PluginApiClient {
  private readonly authHeader: string;

  public constructor(private readonly config: ServerConfig) {
    const raw = `${config.username}:${config.appPassword}`;
    this.authHeader = `Basic ${Buffer.from(raw).toString("base64")}`;
  }

  public async siteInfo(): Promise<Record<string, unknown>> {
    return this.request("GET", "/site-info");
  }

  public async getMyCapabilities(): Promise<{
    auth_method: string
    preset: string
    preset_label: string
    capability_groups: string[]
    tools: string[]
    note: string
  }> {
    return this.request("GET", "/my-capabilities");
  }

  public async listChangelog(filters: Record<string, string | number>): Promise<{
    count: number
    total: number
    items: Array<Record<string, unknown>>
    per_page: number
    offset: number
  }> {
    return this.request("GET", "/changelog", { query: filters as Record<string, string | number | boolean> });
  }

  public async getChange(id: number): Promise<Record<string, unknown>> {
    return this.request("GET", `/changelog/${encodeURIComponent(String(id))}`);
  }

  public async rollbackChange(
    id: number,
    body: { confirmation_token?: string; allow_concurrent_edit_override?: boolean }
  ): Promise<Record<string, unknown>> {
    return this.request("POST", `/changelog/${encodeURIComponent(String(id))}/rollback`, { body });
  }

  public async redoChange(
    id: number,
    body: { confirmation_token?: string }
  ): Promise<Record<string, unknown>> {
    return this.request("POST", `/changelog/${encodeURIComponent(String(id))}/redo`, { body });
  }

  public async rollbackSession(
    sessionId: string,
    body: { confirmation_token?: string; allow_concurrent_edit_override?: boolean }
  ): Promise<Record<string, unknown>> {
    return this.request(
      "POST",
      `/changelog/session/${encodeURIComponent(sessionId)}/rollback`,
      { body }
    );
  }

  public async updateTerm(
    taxonomy: string,
    termId: number,
    body: Record<string, unknown>
  ): Promise<Record<string, unknown>> {
    return this.request(
      "PATCH",
      `/taxonomies/${encodeURIComponent(taxonomy)}/terms/${encodeURIComponent(String(termId))}`,
      { body }
    );
  }

  public async deleteTerm(
    taxonomy: string,
    termId: number
  ): Promise<Record<string, unknown>> {
    return this.request(
      "DELETE",
      `/taxonomies/${encodeURIComponent(taxonomy)}/terms/${encodeURIComponent(String(termId))}`
    );
  }

  public async listUsers(filters: Record<string, string | number>): Promise<Record<string, unknown>> {
    return this.request("GET", "/users", { query: filters as Record<string, string | number | boolean> });
  }

  public async getUser(id: number): Promise<Record<string, unknown>> {
    return this.request("GET", `/users/${encodeURIComponent(String(id))}`);
  }

  public async getAuditLog(filters: Record<string, string | number>): Promise<Record<string, unknown>> {
    return this.request("GET", "/audit-log", { query: filters as Record<string, string | number | boolean> });
  }

  public async listContentTypes(): Promise<string[]> {
    return this.request("GET", "/content-types");
  }

  public async findContent(query: Record<string, unknown>): Promise<ContentRecord[]> {
    return this.request("GET", "/content", { query: query as Record<string, string | number | boolean> });
  }

  public async getContent(id: number): Promise<ContentRecord> {
    return this.request("GET", `/content/${id}`);
  }

  public async createContent(body: Record<string, unknown>): Promise<ContentRecord> {
    return this.request("POST", "/content", { body });
  }

  public async updateContent(id: number, body: Record<string, unknown>): Promise<ContentRecord> {
    return this.request("PATCH", `/content/${id}`, { body });
  }

  public async publishContent(id: number, body: Record<string, unknown>): Promise<ContentRecord> {
    return this.request("POST", `/content/${id}/publish`, { body });
  }

  public async trashContent(id: number): Promise<ContentRecord> {
    return this.request("POST", `/content/${id}/trash`, { body: {} });
  }

  public async restoreContent(id: number): Promise<ContentRecord> {
    return this.request("POST", `/content/${id}/restore`, { body: {} });
  }

  public async listRevisions(id: number): Promise<RevisionRecord[]> {
    return this.request("GET", `/content/${id}/revisions`);
  }

  public async restoreRevision(id: number, revisionId: number): Promise<ContentRecord> {
    return this.request("POST", `/content/${id}/revisions/${revisionId}/restore`, { body: {} });
  }

  public async listAuthors(): Promise<AuthorRecord[]> {
    return this.request("GET", "/authors");
  }

  public async assignAuthor(id: number, authorId: number): Promise<ContentRecord> {
    return this.request("POST", `/content/${id}/author`, { body: { author_id: authorId } });
  }

  public async listTerms(taxonomy: string, query: Record<string, unknown>): Promise<TermRecord[]> {
    return this.request("GET", `/taxonomies/${encodeURIComponent(taxonomy)}/terms`, {
      query: query as Record<string, string | number | boolean>,
    });
  }

  public async createTerm(taxonomy: string, body: Record<string, unknown>): Promise<TermRecord> {
    return this.request("POST", `/taxonomies/${encodeURIComponent(taxonomy)}/terms`, { body });
  }

  public async assignTerms(id: number, body: Record<string, unknown>): Promise<ContentRecord> {
    return this.request("POST", `/content/${id}/terms`, { body });
  }

  public async uploadMedia(body: Record<string, unknown>): Promise<MediaRecord> {
    return this.request("POST", "/media", { body });
  }

  public async searchMedia(query: Record<string, unknown>): Promise<MediaRecord[]> {
    return this.request("GET", "/media", { query: query as Record<string, string | number | boolean> });
  }

  public async updateMedia(id: number, body: Record<string, unknown>): Promise<MediaRecord> {
    return this.request("PATCH", `/media/${id}`, { body });
  }

  public async setFeaturedImage(id: number, mediaId: number | null): Promise<ContentRecord> {
    return this.request("POST", `/content/${id}/featured-image`, { body: { media_id: mediaId } });
  }

  public async insertInlineImage(id: number, body: Record<string, unknown>): Promise<ContentRecord> {
    return this.request("POST", `/content/${id}/inline-image/insert`, { body });
  }

  public async replaceInlineImage(id: number, body: Record<string, unknown>): Promise<ContentRecord> {
    return this.request("POST", `/content/${id}/inline-image/replace`, { body });
  }

  public async removeInlineImage(id: number, body: Record<string, unknown>): Promise<ContentRecord> {
    return this.request("POST", `/content/${id}/inline-image/remove`, { body });
  }

  public async getYoastAnalysis(id: number): Promise<YoastAnalysisRecord> {
    return this.request("GET", `/yoast/analysis/${id}`);
  }

  public async updateYoastMetadata(id: number, body: Record<string, unknown>): Promise<ContentRecord> {
    return this.request("PATCH", `/yoast/metadata/${id}`, { body });
  }

  public async getYoastHeadPreview(id: number): Promise<YoastAnalysisRecord> {
    return this.request("GET", `/yoast/head/${id}`);
  }

  public async getPreviewLink(id: number): Promise<PreviewLinkRecord> {
    return this.request("POST", `/content/${id}/preview-link`, { body: {} });
  }

  public async getMedia(mediaId: number): Promise<unknown> {
    return this.request("GET", `/media/${mediaId}`);
  }

  public async uploadMediaFromUrl(params: Record<string, unknown>): Promise<unknown> {
    return this.request("POST", "/media/from-url", { body: params });
  }

  /**
   * Upload media by reading a local file (path from SessionImageStore).
   * The file is base64-encoded on the MCP server side and sent via the existing
   * /media endpoint — no WordPress plugin changes required, and no base64 ever
   * stays in the Claude context window.
   */
  public async uploadMediaFromPath(
    _filePath: string,
    base64: string,
    filename: string,
    mimeType: string,
    meta: Record<string, unknown> = {}
  ): Promise<unknown> {
    return this.request("POST", "/media", {
      body: {
        filename,
        mime_type: mimeType,
        bytes_base64: base64,
        ...meta,
      },
    });
  }

  public async cloneContent(id: number, params: Record<string, unknown>): Promise<unknown> {
    return this.request("POST", `/content/${id}/clone`, { body: params });
  }

  // ── Post Meta / Custom Fields ───────────────────────────────────────────

  public async getPostMeta(postId: number, key?: string): Promise<unknown> {
    const query: Record<string, string | number | boolean> = {};
    if (key) query.key = key;
    return this.request("GET", `/content/${postId}/meta`, { query });
  }

  public async updatePostMeta(postId: number, meta: Record<string, unknown>): Promise<unknown> {
    return this.request("POST", `/content/${postId}/meta`, { body: meta });
  }

  public async deletePostMeta(postId: number, key: string): Promise<unknown> {
    return this.request("DELETE", `/content/${postId}/meta/${encodeURIComponent(key)}`);
  }

  // ── Comments ──────────────────────────────────────────────────────────

  public async listComments(query: Record<string, unknown> = {}): Promise<unknown> {
    return this.request("GET", "/comments", { query: query as Record<string, string | number | boolean> });
  }

  public async getComment(id: number): Promise<unknown> {
    return this.request("GET", `/comments/${id}`);
  }

  public async createComment(body: Record<string, unknown>): Promise<unknown> {
    return this.request("POST", "/comments", { body });
  }

  public async deleteComment(id: number): Promise<unknown> {
    return this.request("DELETE", `/comments/${id}`);
  }

  public async moderateComment(id: number, action: string): Promise<unknown> {
    return this.request("POST", `/comments/${id}/moderate`, { body: { action } });
  }

  // ── Image Providers ─────────────────────────────────────────────────────

  public async generateImage(body: Record<string, unknown>): Promise<unknown> {
    return this.request("POST", "/image/generate", { body });
  }

  public async searchStockPhotos(query: Record<string, unknown>): Promise<unknown> {
    return this.request("GET", "/image/stock/search", {
      query: query as Record<string, string | number | boolean>,
    });
  }

  public async importStockPhoto(body: Record<string, unknown>): Promise<unknown> {
    return this.request("POST", "/image/stock/import", { body });
  }

  public async listImageProviders(): Promise<unknown> {
    return this.request("GET", "/image/providers");
  }

  public async confirmImage(mediaId: number): Promise<unknown> {
    return this.request("POST", `/image/${mediaId}/confirm`, { body: {} });
  }

  // ── WooCommerce ───────────────────────────────────────────────────────

  public async wooListProducts(filters: Record<string, string | number>): Promise<Record<string, unknown>> {
    return this.request("GET", "/woo/products", { query: filters as Record<string, string | number | boolean> });
  }

  public async wooGetProduct(id: number): Promise<Record<string, unknown>> {
    return this.request("GET", `/woo/products/${encodeURIComponent(String(id))}`);
  }

  public async wooUpdateProductPrice(id: number, body: Record<string, unknown>): Promise<Record<string, unknown>> {
    return this.request("PATCH", `/woo/products/${encodeURIComponent(String(id))}/price`, { body });
  }

  public async wooBulkUpdatePrices(body: Record<string, unknown>): Promise<Record<string, unknown>> {
    return this.request("POST", "/woo/products/bulk-price", { body });
  }

  public async wooListOrders(filters: Record<string, string | number>): Promise<Record<string, unknown>> {
    return this.request("GET", "/woo/orders", { query: filters as Record<string, string | number | boolean> });
  }

  public async wooGetOrder(id: number): Promise<Record<string, unknown>> {
    return this.request("GET", `/woo/orders/${encodeURIComponent(String(id))}`);
  }

  public async wooCreateCoupon(body: Record<string, unknown>): Promise<Record<string, unknown>> {
    return this.request("POST", "/woo/coupons", { body });
  }

  // ── Upload Portal ─────────────────────────────────────────────────────

  public async createUploadSession(body: { ip_binding?: boolean } = {}): Promise<unknown> {
    return this.request("POST", "/upload/session", { body });
  }

  public async getUploadSession(sessionId: string): Promise<unknown> {
    return this.request("GET", `/upload/session/${sessionId}`);
  }

  public async requestReview(postId: number, note?: string): Promise<{ sent_to: string; post_id: number; post_title: string }> {
    return this.request("POST", `/content/${postId}/request-review`, { body: { note: note ?? '' } });
  }

  public async getConnectionCapabilities(): Promise<{
    connection_id: string | null
    capabilities: string[]
    allowed_tools: string[]
    allowed_author_ids: number[] | null
  }> {
    return this.request("GET", "/connection/capabilities");
  }

  public async getPermalinkStructure(): Promise<{
    structure: string
    type: string
    category_base: string
    tag_base: string
    home_url: string
  }> {
    return this.request("GET", "/permalink-structure");
  }

  public async listAbilities(): Promise<unknown> {
    return this.request("GET", "/abilities");
  }

  public async invokeAbility(name: string, args: Record<string, unknown>): Promise<unknown> {
    return this.request("POST", `/abilities/${encodeURIComponent(name)}/execute`, { body: args });
  }

  public async getActiveTheme(): Promise<unknown> {
    return this.request("GET", "/theme");
  }

  public async getThemeMods(): Promise<unknown> {
    return this.request("GET", "/theme/mods");
  }

  public async getCustomCss(): Promise<unknown> {
    return this.request("GET", "/theme/custom-css");
  }

  public async listMenus(): Promise<unknown> {
    return this.request("GET", "/menus");
  }

  public async getMenu(idOrSlug: string | number): Promise<unknown> {
    return this.request("GET", `/menus/${encodeURIComponent(String(idOrSlug))}`);
  }

  public async createMenuItem(menuId: number, item: Record<string, unknown>): Promise<unknown> {
    return this.request("POST", `/menus/${menuId}/items`, { body: item });
  }

  public async updateMenuItem(itemId: number, updates: Record<string, unknown>): Promise<unknown> {
    return this.request("PATCH", `/menu-items/${itemId}`, { body: updates });
  }

  public async deleteMenuItem(itemId: number): Promise<unknown> {
    return this.request("DELETE", `/menu-items/${itemId}`);
  }

  public async reorderMenuItems(menuId: number, order: Array<{ item_id: number; menu_order?: number; parent?: number }>): Promise<unknown> {
    return this.request("POST", `/menus/${menuId}/reorder`, { body: { order } });
  }

  public async getSeoMeta(postId: number): Promise<unknown> {
    return this.request("GET", `/seo/${postId}`);
  }

  public async updateSeoMeta(postId: number, fields: Record<string, unknown>): Promise<unknown> {
    return this.request("POST", `/seo/${postId}`, { body: { fields } });
  }

  public async getOption(key: string): Promise<unknown> {
    return this.request("GET", `/options/${encodeURIComponent(key)}`);
  }

  public async updateOption(key: string, value: unknown): Promise<unknown> {
    return this.request("POST", `/options/${encodeURIComponent(key)}`, { body: { value } });
  }

  public async getPluginSettings(slug: string): Promise<unknown> {
    return this.request("GET", `/plugin-settings/${encodeURIComponent(slug)}`);
  }

  public async getTermMeta(termId: number, key?: string): Promise<unknown> {
    const path = `/terms/${termId}/meta` + (key ? `?key=${encodeURIComponent(key)}` : "");
    return this.request("GET", path);
  }

  public async updateTermMeta(termId: number, meta: Record<string, unknown>): Promise<unknown> {
    return this.request("POST", `/terms/${termId}/meta`, { body: meta });
  }

  public async deleteTermMeta(termId: number, metaKey: string): Promise<unknown> {
    return this.request("DELETE", `/terms/${termId}/meta/${encodeURIComponent(metaKey)}`);
  }

  public async listPlugins(): Promise<{
    count: number
    plugins: Array<{
      slug: string
      file: string
      name: string
      version: string
      description: string
      author: string
      author_uri: string
      plugin_uri: string
      requires_wp: string
      requires_php: string
      network: boolean
      active: boolean
    }>
  }> {
    return this.request("GET", "/plugins");
  }

  public async listThemes(): Promise<{
    count: number
    themes: Array<{
      stylesheet: string
      name: string
      version: string
      description: string
      author: string
      theme_uri: string
      requires_wp: string
      requires_php: string
      parent: string | null
      active: boolean
    }>
  }> {
    return this.request("GET", "/themes");
  }

  public async updatePermalinkStructure(structure: string): Promise<{
    structure: string
    type: string
    flushed: boolean
  }> {
    return this.request("POST", "/permalink-structure", { body: { structure } });
  }

  private async request<T>(method: string, path: string, options: RequestOptions = {}): Promise<T> {
    const url = new URL(`${this.config.wpPluginBaseUrl}${path}`);

    for (const [key, value] of Object.entries(options.query ?? {})) {
      if (value === null || value === undefined || value === "") {
        continue;
      }

      url.searchParams.set(key, String(value));
    }

    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), this.config.httpTimeout);

    try {
      const response = await fetch(url, {
        method,
        headers: {
          Authorization: this.authHeader,
          "Content-Type": "application/json",
        },
        body: options.body ? JSON.stringify(options.body) : undefined,
        signal: controller.signal,
      });

      const payload = (await response.json().catch(() => ({}))) as ApiEnvelope<T>;

      if (!response.ok || !payload.success) {
        const error = payload.error;
        throw new ToolError(error?.code ?? "PLUGIN_REQUEST_FAILED", error?.message ?? "Plugin request failed", {
          details: error?.details ?? payload,
          httpStatus: error?.http_status ?? response.status,
          retryable: Boolean(error?.retryable),
        });
      }

      return payload.data as T;
    } catch (error) {
      if (error instanceof ToolError) {
        throw error;
      }

      if (error instanceof DOMException && error.name === "AbortError") {
        throw new ToolError("PLUGIN_REQUEST_TIMEOUT", `Request timed out after ${this.config.httpTimeout}ms`, {
          retryable: true,
        });
      }

      throw new ToolError("PLUGIN_REQUEST_FAILED", "Failed to call WordPress plugin API", {
        details: error,
      });
    } finally {
      clearTimeout(timeout);
    }
  }
}
