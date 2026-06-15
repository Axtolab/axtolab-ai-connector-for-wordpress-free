export type ToolConsentTier = "disallow" | "ask" | "always";

export type ToolConsentPolicyMap = Record<string, ToolConsentTier>;

export interface ToolConsentContext {
  toolName: string;
  action: string;
  key: string;
  tier: ToolConsentTier;
}

const DEFAULT_POLICY: ToolConsentPolicyMap = {
  publish_content: "ask",
  trash_content: "ask",
  delete_content: "ask",
  restore_content: "ask",
  restore_revision: "ask",
  woo_update_product_price: "ask",
  woo_bulk_update_prices: "ask",
  woo_create_coupon: "ask",
  generate_image_in_context: "ask",
  batch_regenerate_post_images: "ask",
  delete_brand_kit: "ask",
  delete_term: "ask",
  delete_post_meta: "ask",
  delete_comment: "ask",
  delete_menu_item: "ask",
  delete_term_meta: "ask",
  create_draft: "always",
  update_content: "always",
  update_media: "always",
  set_featured_image: "always",
};

const TOOL_ACTIONS: Record<string, string> = {
  wp_publish_content: "publish_content",
  wp_trash_content: "trash_content",
  wp_delete_content: "delete_content",
  wp_restore_content: "restore_content",
  wp_restore_revision: "restore_revision",
  wp_woo_update_product_price: "woo_update_product_price",
  wp_woo_bulk_update_prices: "woo_bulk_update_prices",
  wp_woo_create_coupon: "woo_create_coupon",
  wp_generate_image_in_context: "generate_image_in_context",
  wp_batch_regenerate_post_images: "batch_regenerate_post_images",
  wp_delete_brand_kit: "delete_brand_kit",
  wp_delete_term: "delete_term",
  wp_delete_post_meta: "delete_post_meta",
  wp_delete_comment: "delete_comment",
  wp_delete_menu_item: "delete_menu_item",
  wp_delete_term_meta: "delete_term_meta",
  wp_create_draft: "create_draft",
  wp_update_content: "update_content",
  wp_update_media: "update_media",
  wp_set_featured_image: "set_featured_image",
};

const DESTRUCTIVE_MARKERS = ["delete", "trash", "restore", "publish", "coupon", "price", "bulk", "batch"];

export class ToolConsentPolicy {
  public static defaults(): ToolConsentPolicyMap {
    return { ...DEFAULT_POLICY };
  }

  public static normalize(rawPolicy: unknown): ToolConsentPolicyMap {
    const clean: ToolConsentPolicyMap = { ...DEFAULT_POLICY };
    if (!rawPolicy || typeof rawPolicy !== "object") {
      return clean;
    }

    for (const [rawAction, rawTier] of Object.entries(rawPolicy as Record<string, unknown>)) {
      const action = sanitizeKey(rawAction);
      const tier = normalizeTier(rawTier);
      if (action && tier) {
        clean[action] = tier;
      }
    }

    return clean;
  }

  public static contextForTool(
    toolName: string,
    input: Record<string, unknown>,
    policy: ToolConsentPolicyMap
  ): ToolConsentContext {
    const action = this.actionForTool(toolName);
    const tier = policy[action] ?? (this.looksDestructive(action, toolName) ? "ask" : "always");

    return {
      toolName,
      action,
      key: this.confirmationKey(action, input),
      tier,
    };
  }

  public static actionForTool(toolName: string): string {
    return TOOL_ACTIONS[toolName] ?? (toolName.startsWith("wp_") ? toolName.slice(3) : toolName);
  }

  public static looksDestructive(action: string, toolName: string): boolean {
    const haystack = `${action} ${toolName}`.toLowerCase();
    return DESTRUCTIVE_MARKERS.some((marker) => haystack.includes(marker));
  }

  private static confirmationKey(action: string, input: Record<string, unknown>): string {
    const contentType = sanitizeKey(String(input.content_type ?? "post"));
    const id = toInt(input.id ?? input.post_id ?? input.product_id ?? input.media_id);
    const revisionId = toInt(input.revision_id);

    switch (action) {
      case "publish_content":
        return `${contentType}:${id}:publish`;
      case "trash_content":
        return `${contentType}:${id}:trash`;
      case "delete_content":
        return `${contentType}:${id}:delete`;
      case "restore_content":
        return `${contentType}:${id}:restore`;
      case "restore_revision":
        return `${contentType}:${id}:revision:${revisionId}`;
      case "woo_update_product_price":
        return `woo-product:${id}:price`;
      case "woo_bulk_update_prices":
        return `woo-bulk-price:${stableHash(input)}`;
      case "woo_create_coupon":
        return `woo-coupon:${sanitizeKey(String(input.code ?? "new"))}`;
      case "generate_image_in_context":
        return `image-context:${id}:${stableHash(input)}`;
      case "batch_regenerate_post_images":
        return `image-batch:${stableHash(input)}`;
      case "delete_term":
        return `${sanitizeKey(String(input.taxonomy ?? "term"))}:${toInt(input.term_id)}:delete`;
      case "delete_post_meta":
        return `post:${id}:meta:${sanitizeKey(String(input.key ?? input.meta_key ?? ""))}:delete`;
      case "delete_comment":
        return `comment:${id}:delete`;
      case "delete_menu_item":
        return `menu-item:${toInt(input.item_id ?? id)}:delete`;
      case "delete_term_meta":
        return `term:${toInt(input.term_id)}:meta:${sanitizeKey(String(input.key ?? input.meta_key ?? ""))}:delete`;
      case "delete_brand_kit":
        return `brand-kit:${id}:delete`;
      default:
        return `${action}:${stableHash(input)}`;
    }
  }
}

function normalizeTier(rawTier: unknown): ToolConsentTier | null {
  if (typeof rawTier !== "string") {
    return null;
  }
  const tier = rawTier.toLowerCase();
  return tier === "disallow" || tier === "ask" || tier === "always" ? tier : "ask";
}

function sanitizeKey(value: string): string {
  return value.toLowerCase().replace(/[^a-z0-9_:-]+/g, "_").replace(/^_+|_+$/g, "");
}

function toInt(value: unknown): number {
  const parsed = Number.parseInt(String(value ?? 0), 10);
  return Number.isFinite(parsed) ? parsed : 0;
}

function stableHash(input: Record<string, unknown>): string {
  const withoutToken = { ...input };
  delete withoutToken.confirmation_token;

  let hash = 0;
  const json = JSON.stringify(sortValue(withoutToken));
  for (let i = 0; i < json.length; i += 1) {
    hash = ((hash << 5) - hash + json.charCodeAt(i)) | 0;
  }
  return Math.abs(hash).toString(16).padStart(8, "0");
}

function sortValue(value: unknown): unknown {
  if (Array.isArray(value)) {
    return value.map(sortValue);
  }
  if (value && typeof value === "object") {
    return Object.fromEntries(
      Object.entries(value as Record<string, unknown>)
        .sort(([a], [b]) => a.localeCompare(b))
        .map(([key, nested]) => [key, sortValue(nested)])
    );
  }
  return value;
}
