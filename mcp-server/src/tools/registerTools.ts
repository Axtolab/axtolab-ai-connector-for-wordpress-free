import * as path from "node:path";
import { z } from "zod";
import type { ContentRecord } from "../types/contracts.js";
import { ConfirmationService } from "../services/confirmationService.js";
import { RateLimiter } from "../services/rateLimiter.js";
import { SessionImageStore } from "../services/sessionImageStore.js";
import { SiteManager } from "../services/siteManager.js";
import { serializeError, ToolError } from "../utils/errors.js";
import { logger } from "../utils/logger.js";
import { toHtml, type ContentFormat } from "../services/markdownService.js";

interface ToolContext {
  server: any;
  siteManager: SiteManager;
  confirmations: ConfirmationService;
  rateLimiter: RateLimiter;
  sessionImageStore: SessionImageStore;
}

const ContentTypeSchema = z.enum(["post", "page", "featured_item"]);
const PlacementSchema = z.enum(["start", "end", "before_heading", "after_heading", "marker"]);

function respond(data: unknown): { content: Array<{ type: string; text: string }> } {
  return {
    content: [{ type: "text", text: JSON.stringify(data, null, 2) }],
  };
}

function requireConfirmation(
  confirmations: ConfirmationService,
  action: string,
  key: string,
  input: unknown,
  confirmationToken?: string
): { confirmed: true } | { confirmed: false; payload: unknown } {
  if (!confirmationToken) {
    const issued = confirmations.issue(action, key, input);
    return {
      confirmed: false,
      payload: {
        requires_confirmation: true,
        confirmation_token: issued.token,
        confirmation_payload: issued.payload,
      },
    };
  }

  confirmations.consume(confirmationToken, action, key);
  return { confirmed: true };
}

// Tools that are safe to expose even when the active WordPress connection is
// fail-closed (for example, Free on multisite). Anything that can call the
// blocked WordPress site must stay hidden/denied in that state.
const BLOCKED_SITE_AVAILABLE_TOOLS = new Set([
  'wp_connect_site', 'wp_list_sites', 'wp_switch_site', 'wp_find_media_file',
])

// Local/helper tools that bypass per-connection allowed_tools checks in normal
// operation. wp_upload_media_from_path still calls the WordPress site, so it is
// intentionally not in BLOCKED_SITE_AVAILABLE_TOOLS.
const CAPABILITY_CHECK_BYPASS_TOOLS = new Set([
  ...BLOCKED_SITE_AVAILABLE_TOOLS, 'wp_upload_media_from_path',
])

export function registerTools(context: ToolContext): void {
  const { server, siteManager, confirmations, rateLimiter, sessionImageStore } = context;

  // Convenience: get the active site's services. Called inside each tool handler
  // so it always returns the CURRENT site (respects wp_switch_site).
  function site() {
    return siteManager.getCurrent();
  }

  type RegisteredTool = { enable(): void; disable(): void };
  const gatedTools = new Map<string, RegisteredTool>();
  const registerServerTool = server.tool.bind(server);
  server.tool = (name: string, ...args: unknown[]): RegisteredTool => {
    const registered = registerServerTool(name, ...args);
    if (!BLOCKED_SITE_AVAILABLE_TOOLS.has(name)) {
      gatedTools.set(name, registered);
    }
    return registered;
  };

  let generateImageTool: RegisteredTool | null = null;

  function syncImageGenerationToolVisibility(): void {
    if (!generateImageTool) return;

    const { allowedTools, connectionCapabilityError } = siteManager.getCurrent();
    if (connectionCapabilityError || (allowedTools && !allowedTools.includes("wp_generate_image"))) {
      generateImageTool.disable();
      return;
    }

    generateImageTool.enable();
  }

  function syncToolVisibility(): void {
    const { connectionCapabilityError } = siteManager.getCurrent();
    for (const tool of gatedTools.values()) {
      if (connectionCapabilityError) {
        tool.disable();
      } else {
        tool.enable();
      }
    }
    syncImageGenerationToolVisibility();
  }

  async function runToolWithLimit(name: string, input: Record<string, unknown>, fn: () => Promise<unknown>) {
    try {
      const { allowedTools, connectionCapabilityError } = siteManager.getCurrent()
      if (connectionCapabilityError && !BLOCKED_SITE_AVAILABLE_TOOLS.has(name)) {
        return respond({
          success: false,
          tool: name,
          error: {
            code: connectionCapabilityError.code,
            message: connectionCapabilityError.message,
          },
        })
      }

      if (!CAPABILITY_CHECK_BYPASS_TOOLS.has(name)) {
        if (allowedTools && !allowedTools.includes(name)) {
          return respond({
            success: false,
            tool: name,
            error: {
              code: 'capability_denied',
              message: `This connection does not have permission to use ${name}. ` +
                'Ask your WordPress admin to update permissions in Axtolab AI Connector → Connections.',
            },
          })
        }
      }

      rateLimiter.consume();
      const result = await fn();
      return respond({ success: true, tool: name, data: result });
    } catch (error) {
      const serialized = serializeError(error);
      logger.error(`Tool ${name} failed`, { input, error: serialized });
      return respond({ success: false, tool: name, error: serialized });
    }
  }

  server.tool("wp_site_info", "Get site and capability metadata.", {}, async () =>
    runToolWithLimit("wp_site_info", {}, async () => site().client.siteInfo())
  );

  // wp_get_my_capabilities — connection introspection. Reads the active
  // connection's capability groups, the matching named preset, and the
  // resolved tool list from the plugin so the AI can plan work without
  // trial-and-error. Mirrors the same data the admin UI shows under
  // WordPress → Axtolab → AI Connector → Connections.
  server.tool(
    "wp_get_my_capabilities",
    "Return the capability groups, named preset, and tool list available to the current AI connection. Use this to know up-front what you're allowed to do (e.g. whether publishing or trash/restore are enabled) instead of discovering by trial-and-error.",
    {},
    async () =>
      runToolWithLimit("wp_get_my_capabilities", {}, async () =>
        site().client.getMyCapabilities()
      )
  );

  // wp_get_changelog — list recorded changes captured by the changelog
  // (Phase 5). Read-only; full snapshots are returned by wp_get_change.
  server.tool(
    "wp_get_changelog",
    "List recorded changes captured by the AI Connector changelog: posts/pages/options/menus/etc. that were created, updated, trashed, restored or published by AI tool calls. Use to review what's happened in the current session, find a specific change to undo, or audit recent activity. Filter by session_id, target_type (post/option/menu/etc.), target_id, tool_name, action, status (rolled_back / pending), or since (ISO 8601 datetime). Snapshots are excluded from this list — call wp_get_change with a row id for the full before/after diff.",
    {
      session_id: z.string().optional().describe("Filter to one MCP session"),
      target_type: z.string().optional().describe("post | option | menu | term | theme_mod | ..."),
      target_id: z.string().optional().describe("Identifier within the target type"),
      tool_name: z.string().optional().describe("e.g. wp_update_content"),
      action: z.string().optional().describe("create | update | trash | restore | publish | delete"),
      status: z.enum(["rolled_back", "pending", "all"]).optional().describe("rolled_back = already undone; pending = still in effect"),
      since: z.string().optional().describe("ISO 8601 / mysql datetime"),
      per_page: z.number().int().min(1).max(200).optional(),
      offset: z.number().int().min(0).optional(),
    },
    async (args: Record<string, string | number | undefined>) =>
      runToolWithLimit("wp_get_changelog", args, async () => {
        const filters: Record<string, string | number> = {};
        for (const [k, v] of Object.entries(args)) {
          if (v !== undefined && v !== "") {
            filters[k] = v as string | number;
          }
        }
        return site().client.listChangelog(filters);
      })
  );

  // wp_get_change — fetch a single changelog row including the full
  // before/after snapshot. Used as the precursor to wp_rollback_change.
  server.tool(
    "wp_get_change",
    "Fetch a single changelog entry by id, including the full before/after snapshot. Use this after wp_get_changelog has identified the row of interest, especially before calling wp_rollback_change so the user can see exactly what will be reverted.",
    {
      id: z.number().int().positive().describe("Changelog row id"),
    },
    async (args: { id: number }) =>
      runToolWithLimit("wp_get_change", args, async () => site().client.getChange(args.id))
  );

  // wp_rollback_change — undo one captured change. Two-step flow:
  //   1) Call without confirmation_token → server returns a token
  //      and a description of what the rollback will do.
  //   2) Show the description to the user; on approval, re-call
  //      with the confirmation_token to execute.
  // Currently restores `post` target type only. Other target types
  // (option, menu, term, theme_mod, ...) ship in a follow-up.
  server.tool(
    "wp_rollback_change",
    "Undo a single change recorded in the changelog. Two-step: call once without confirmation_token to receive a token + description of what will be reverted; show that to the user; then call again with the confirmation_token to execute. The server compares the post's current modified time against the change's recorded after-snapshot — if the post was edited after the change, rollback fails with a concurrent_edit error unless allow_concurrent_edit_override=true. Currently rolls back posts/pages only; other target types return rollback_not_supported.",
    {
      id: z.number().int().positive().describe("Changelog row id (from wp_get_changelog)"),
      confirmation_token: z.string().optional().describe("Token from the previous call. Omit on the first call."),
      allow_concurrent_edit_override: z
        .boolean()
        .optional()
        .describe("Force rollback even if the target was modified after the captured change. Default false."),
    },
    async (args: { id: number; confirmation_token?: string; allow_concurrent_edit_override?: boolean }) =>
      runToolWithLimit("wp_rollback_change", args, async () => {
        const body: { confirmation_token?: string; allow_concurrent_edit_override?: boolean } = {};
        if (args.confirmation_token !== undefined) body.confirmation_token = args.confirmation_token;
        if (args.allow_concurrent_edit_override !== undefined) {
          body.allow_concurrent_edit_override = args.allow_concurrent_edit_override;
        }
        return site().client.rollbackChange(args.id, body);
      })
  );

  // wp_update_term / wp_delete_term — complete the term CRUD surface.
  // Captured in the changelog so they can be rolled back like any
  // other change.
  server.tool(
    "wp_update_term",
    "Update a term (category/tag/custom taxonomy term) — change name, slug, description, or parent. Captured in the changelog so it can be rolled back via wp_rollback_change.",
    {
      taxonomy: z.string().min(1),
      term_id: z.number().int().positive(),
      name: z.string().optional(),
      slug: z.string().optional(),
      description: z.string().optional(),
      parent: z.number().int().nonnegative().optional(),
    },
    async (args: { taxonomy: string; term_id: number; name?: string; slug?: string; description?: string; parent?: number }) =>
      runToolWithLimit("wp_update_term", args, async () => {
        const { taxonomy, term_id, ...body } = args;
        return site().client.updateTerm(taxonomy, term_id, body);
      })
  );

  server.tool(
    "wp_delete_term",
    "Delete a term. Posts assigned to the term are not deleted, just unassigned. Captured in the changelog; rollback re-creates the term (term_id may differ).",
    {
      taxonomy: z.string().min(1),
      term_id: z.number().int().positive(),
    },
    async (args: { taxonomy: string; term_id: number }) =>
      runToolWithLimit("wp_delete_term", args, async () =>
        site().client.deleteTerm(args.taxonomy, args.term_id)
      )
  );

  // wp_list_users / wp_get_user — read-only user discovery. User
  // CRUD is intentionally out of free core (in the User Management
  // add-on); these read tools are common enough they belong in core.
  server.tool(
    "wp_list_users",
    "List WordPress users with filters. Read-only. Use to find users by role, email, or name; for example to assign authors, look up commenters, or audit accounts. Filters: search (matches login/email/display_name/nicename), role (administrator/editor/author/etc.), per_page (max 100), offset.",
    {
      search: z.string().optional(),
      role: z.string().optional(),
      per_page: z.number().int().min(1).max(100).optional(),
      offset: z.number().int().min(0).optional(),
    },
    async (args: Record<string, string | number | undefined>) =>
      runToolWithLimit("wp_list_users", args, async () => {
        const f: Record<string, string | number> = {};
        for (const [k, v] of Object.entries(args)) {
          if (v !== undefined && v !== "") f[k] = v as string | number;
        }
        return site().client.listUsers(f);
      })
  );

  server.tool(
    "wp_get_user",
    "Fetch a single WordPress user by id. Returns username, display_name, email, roles, registered date, profile URL, and bio.",
    { id: z.number().int().positive() },
    async (args: { id: number }) =>
      runToolWithLimit("wp_get_user", args, async () => site().client.getUser(args.id))
  );

  // wp_get_audit_log — read recent activity log entries. Lets the
  // AI review its own actions for self-correction without needing
  // admin-UI access. Filters: tool_name, connection_id, outcome.
  server.tool(
    "wp_get_audit_log",
    "Read recent MCP tool-call activity for this site. Use to review what's been done in the current session (combine with wp_get_my_capabilities to know what was attempted vs allowed) or to debug failures (filter by outcome=error).",
    {
      tool_name: z.string().optional(),
      connection_id: z.string().optional(),
      outcome: z.enum(["success", "error"]).optional(),
      per_page: z.number().int().min(1).max(100).optional(),
      offset: z.number().int().min(0).optional(),
    },
    async (args: Record<string, string | number | undefined>) =>
      runToolWithLimit("wp_get_audit_log", args, async () => {
        const f: Record<string, string | number> = {};
        for (const [k, v] of Object.entries(args)) {
          if (v !== undefined && v !== "") f[k] = v as string | number;
        }
        return site().client.getAuditLog(f);
      })
  );

  // ── WooCommerce tools ────────────────────────────────────────────────
  // Basic WooCommerce read/write tools ship in the connector. WooCommerce
  // itself must be active, and writes are guarded server-side + captured
  // in Roll Back.

  server.tool(
    "wp_woo_list_products",
    "List WooCommerce products with pagination, status, and search. Read-only. Requires WooCommerce.",
    {
      per_page: z.number().int().min(1).max(100).optional(),
      page: z.number().int().min(1).optional(),
      status: z.string().optional(),
      search: z.string().optional(),
    },
    async (args: Record<string, string | number | undefined>) =>
      runToolWithLimit("wp_woo_list_products", args, async () => {
        const f: Record<string, string | number> = {};
        for (const [k, v] of Object.entries(args)) { if (v !== undefined && v !== "") f[k] = v as string | number; }
        return site().client.wooListProducts(f);
      })
  );

  server.tool(
    "wp_woo_get_product",
    "Get a single WooCommerce product including variations, categories, tags, descriptions, stock, prices.",
    { id: z.number().int().positive() },
    async (args: { id: number }) =>
      runToolWithLimit("wp_woo_get_product", args, async () => site().client.wooGetProduct(args.id))
  );

  server.tool(
    "wp_woo_update_product_price",
    "Update a single WooCommerce product's regular_price and/or sale_price. SAFETY: refused by guardrail if the percentage change exceeds the configured cap (default 20%). Captured in the changelog so the change can be rolled back via wp_rollback_change.",
    {
      id: z.number().int().positive(),
      regular_price: z.number().nonnegative().optional(),
      sale_price: z.number().nonnegative().optional().describe("Set 0 to remove the sale price"),
    },
    async (args: { id: number; regular_price?: number; sale_price?: number }) =>
      runToolWithLimit("wp_woo_update_product_price", args, async () => {
        const { id, ...body } = args;
        return site().client.wooUpdateProductPrice(id, body);
      })
  );

  server.tool(
    "wp_woo_bulk_update_prices",
    "Bulk update prices across up to 100 products. Provide either percent_change (e.g. +10 raises all by 10%) or set_to (uniform new price). SAFETY: per-product guardrail; products whose change exceeds the cap are skipped. Each successful product change is reversible via wp_rollback_change or wp_rollback_session.",
    {
      product_ids: z.array(z.number().int().positive()).min(1).max(100),
      percent_change: z.number().optional(),
      set_to: z.number().nonnegative().optional(),
    },
    async (args: Record<string, unknown>) =>
      runToolWithLimit("wp_woo_bulk_update_prices", args, async () =>
        site().client.wooBulkUpdatePrices(args as Record<string, unknown>)
      )
  );

  server.tool(
    "wp_woo_list_orders",
    "List WooCommerce orders with status filter. Read-only.",
    {
      per_page: z.number().int().min(1).max(100).optional(),
      page: z.number().int().min(1).optional(),
      status: z.string().optional().describe("any | pending | processing | on-hold | completed | cancelled | refunded | failed"),
    },
    async (args: Record<string, string | number | undefined>) =>
      runToolWithLimit("wp_woo_list_orders", args, async () => {
        const f: Record<string, string | number> = {};
        for (const [k, v] of Object.entries(args)) { if (v !== undefined && v !== "") f[k] = v as string | number; }
        return site().client.wooListOrders(f);
      })
  );

  server.tool(
    "wp_woo_get_order",
    "Get a single order with billing/shipping addresses and line items.",
    { id: z.number().int().positive() },
    async (args: { id: number }) =>
      runToolWithLimit("wp_woo_get_order", args, async () => site().client.wooGetOrder(args.id))
  );

  server.tool(
    "wp_woo_create_coupon",
    "Create a WooCommerce coupon. SAFETY GATES: refuses percent-type coupons over the configured max (default 50%); refuses sitewide coupons without minimum_amount unless product/category restrictions are set. Captured for rollback.",
    {
      code: z.string().min(1),
      discount_type: z.enum(["fixed_cart", "fixed_product", "percent", "percent_product"]).optional(),
      amount: z.number().nonnegative(),
      minimum_amount: z.number().nonnegative().optional(),
      product_ids: z.array(z.number().int().positive()).optional().describe("Restrict coupon to specific product IDs"),
      product_categories: z.array(z.number().int().positive()).optional().describe("Restrict coupon to WooCommerce product category term IDs"),
      expires_at: z.string().optional(),
      usage_limit: z.number().int().positive().optional(),
    },
    async (args: Record<string, unknown>) =>
      runToolWithLimit("wp_woo_create_coupon", args, async () =>
        site().client.wooCreateCoupon(args as Record<string, unknown>)
      )
  );

  // wp_rollback_session — undo every pending change in one MCP session
  // in LIFO order (newest first). Skips already-rolled-back rows.
  // Continues past per-change failures and returns a per-change status.
  server.tool(
    "wp_rollback_session",
    "Undo every pending (not-yet-rolled-back) change recorded in a given MCP session, in LIFO order. Same two-step confirmation flow as wp_rollback_change but at session granularity. Returns a per-change result array; concurrent-edit guards still apply per-change unless allow_concurrent_edit_override=true.",
    {
      session_id: z.string().min(1).describe("MCP session id (from a recent change row's session_id field)"),
      confirmation_token: z.string().optional(),
      allow_concurrent_edit_override: z.boolean().optional(),
    },
    async (args: { session_id: string; confirmation_token?: string; allow_concurrent_edit_override?: boolean }) =>
      runToolWithLimit("wp_rollback_session", args, async () => {
        const body: { confirmation_token?: string; allow_concurrent_edit_override?: boolean } = {};
        if (args.confirmation_token !== undefined) body.confirmation_token = args.confirmation_token;
        if (args.allow_concurrent_edit_override !== undefined) {
          body.allow_concurrent_edit_override = args.allow_concurrent_edit_override;
        }
        return site().client.rollbackSession(args.session_id, body);
      })
  );

  // wp_redo_change — re-apply a rolled-back change. Same two-step
  // confirmation flow as rollback. Restores the original change's
  // `after` snapshot, clears rolled_back_at on the original, and
  // records a new changelog row linked back via redo_of_change_id.
  server.tool(
    "wp_redo_change",
    "Re-apply a previously rolled-back change. Pass the original change_id (the one that was rolled back). Two-step: call once without confirmation_token to receive a token; then call again with the token to execute. The original row's rolled_back_at marker is cleared and a new redo entry is recorded linked to it.",
    {
      id: z.number().int().positive().describe("Original change row id (the one that was rolled back)"),
      confirmation_token: z.string().optional(),
    },
    async (args: { id: number; confirmation_token?: string }) =>
      runToolWithLimit("wp_redo_change", args, async () => {
        const body: { confirmation_token?: string } = {};
        if (args.confirmation_token !== undefined) body.confirmation_token = args.confirmation_token;
        return site().client.redoChange(args.id, body);
      })
  );

  server.tool(
    "wp_getting_started",
    [
      "CALL THIS FIRST before any other WordPress tools at the start of a session.",
      "Returns site context, theme info, and the complete editorial workflow guide.",
      "Provides everything Claude needs to make correct decisions about content structure, shortcodes, images, SEO, and publishing.",
      "Do not proceed with content operations without calling this first.",
    ].join(" "),
    {},
    async () =>
      runToolWithLimit("wp_getting_started", {}, async () => {
        const siteInfo = await site().client.siteInfo();
        return {
          MANDATORY_RULES: [
            "⛔ RULE 1 — MARKDOWN FILE FIRST, NO EXCEPTIONS: Before calling wp_create_draft you MUST: (a) call create_file to write a .md file containing the metadata header + full post/page content, (b) call present_files to show it to the user, (c) wait for the user to explicitly say something like 'looks good', 'create the draft', 'send it to WordPress', or 'ready'. The words 'go ahead', 'start writing', 'yes', or 'sure' do NOT count as draft approval — they mean begin writing the .md file.",
            "⛔ RULE 2 — THE .md FILE IS THE WORKING DOCUMENT: Every edit to title, body copy, images, categories, tags, slug, SEO fields, or schedule happens by editing the .md file first using str_replace_based_edit on the specific changed line(s). The user reads the .md file to review the post — not WordPress, not a preview link. Only after all edits are done in the .md file does content get pushed to WordPress.",
            "⛔ RULE 3 — AFTER wp_create_draft IS CALLED: The .md file remains the source of truth. If the user asks for any change, edit the .md file first (str_replace the changed part only), then ask: 'I've updated the draft file — should I also push this change to WordPress?' Do not call wp_update_content without asking first.",
            "⛔ RULE 4 — SCHEDULE BEFORE PUBLISH: Before calling wp_publish_content, ALWAYS ask: 'Should I publish this immediately, or would you like to schedule it? If scheduling, give me a date, time, and timezone.'",
            "⛔ RULE 5 — NO IMAGE BYTES IN CONVERSATION: Never ask for base64 or image data. To upload a local image: call wp_find_media_file with the filename → get file_path → call wp_upload_media_from_path. The chat-visible path (/mnt/user-data/...) is a container path — always use wp_find_media_file to locate the real file on disk.",
          ],
          site: siteInfo,
          workflow: {
            core_principles: [
              "ARTIFACT-FIRST: Always draft content as a conversation artifact before creating anything in WordPress. Let the user review and iterate.",
              "ITERATIVE: The flow is artifact → user approves → wp_create_draft → iterate with wp_update_content → preview → publish. Never one-shot.",
              "KEEP ARTIFACT IN SYNC: After every wp_update_content call, patch the artifact incrementally using str_replace — never rewrite the entire artifact. Only update the changed section.",
              "METADATA HEADER: Every content artifact must start with a metadata dashboard (see metadata_header_format) separated by a divider line so the user can see completion status at a glance.",
            ],
            structured_setup: [
              "PHASE 1 — IDEATION (always start here):",
              "Ask the user: 'Would you like to go through this step-by-step (guided), or just have a conversation and I'll follow your lead (conversational)?'",
              "",
              "GUIDED MODE:",
              "  1. Topic & angle — 'What's the article about? What's the core message? Who's the audience?'",
              "  2. Context gathering — 'Would you like me to pull in any context first?' Options:",
              "     • Search existing articles on this site about the same topic (wp_find_content)",
              "     • Pull the chosen author's recent posts to match their voice (wp_find_content by author + wp_get_content)",
              "     • User shares reference material, links, or ideas",
              "  3. Final brief — 'Any final instructions? Tone, length, structure, things to avoid?'",
              "  4. THEN create the .md artifact with the first draft. Metadata header starts mostly ❌ — that's expected.",
              "",
              "CONVERSATIONAL MODE:",
              "  Have a natural back-and-forth. Let the topic, context, and direction emerge organically from conversation.",
              "  Still offer to gather context (site articles, author voice) as it becomes relevant.",
              "  NEVER produce a draft until the user explicitly signals readiness ('ok write it', 'draft it', 'go ahead').",
              "",
              "BOTH MODES — NEVER DO THIS:",
              "  ✗ Start drafting without a clear topic and user signal",
              "  ✗ Front-load metadata questions (author, categories, SEO) before content direction is agreed",
              "  ✗ Auto-advance between phases without user input",
              "",
              "PHASE 2 — DRAFTING (iterate until the user is happy):",
              "  The .md file is the source of truth. Iterate freely — restructure, rewrite, refine. Images can flow in naturally during this phase.",
              "  STOP AND WAIT for user feedback after each draft revision. Do not auto-advance to packaging.",
              "",
              "PHASE 3 — PACKAGING (only when content is done):",
              "  When the user signals the content is ready, ask about metadata in a single batch:",
              "  • Author, categories, tags",
              "  • Featured image (already uploaded during drafting, or search the library now)",
              "  • SEO focus keyphrase and meta description",
              "  • Schedule or publish immediately",
              "  Update the metadata header as each item is resolved. Then send to WordPress.",
              "",
              "KEY: Content first, packaging second. Never front-load metadata. STOP between phases — do not auto-advance.",
            ],
            artifact_format: [
              "Every content artifact must begin with a METADATA HEADER block (see metadata_header_format), then a --- divider, then the content body.",
              "The metadata header is the user's at-a-glance status panel — always keep it up to date.",
              "INCREMENTAL EDITS: When updating an artifact, use str_replace to patch only the changed sections. Do NOT delete and recreate the whole artifact. This is much faster for the user.",
              "Example incremental edit: change one paragraph or one metadata field — patch just that part.",
            ],
            metadata_header_format: {
              description: "Always include this block at the very top of every content artifact, separated from the body by ---",
              example: [
                "## 📋 Post Metadata",
                "| Field | Status | Value |",
                "|---|---|---|",
                "| **Title** | ✅ | Why Agentic AI Is the Missing Layer |",
                "| **Slug** | ✅ | why-agentic-ai-missing-layer |",
                "| **Author** | ✅ | Jane Doe |",
                "| **Categories** | ✅ | AI, Industrial Operations |",
                "| **Tags** | ❌ | not set |",
                "| **Featured Image** | ✅ | hero.jpg (media ID 12345) |",
                "| **Excerpt** | ✅ | A short summary... |",
                "| **Yoast Focus KW** | ✅ | agentic AI industrial |",
                "| **Yoast SEO Title** | ✅ | Why Agentic AI Is the Missing Layer | Axtolab |",
                "| **Yoast Meta Desc** | ✅ | 142 chars — within range |",
                "| **Schedule** | ❌ | publish immediately |",
                "",
                "---",
              ].join("\n"),
            },
            editing_existing_content: [
              "Fetch post with wp_get_content.",
              "Immediately create a MARKDOWN (.md) artifact — start with the metadata header block, then --- divider, then the content body.",
              "Use MARKDOWN format for the artifact body, NOT raw HTML — the artifact is for reading and editing copy.",
              "After every mutation, patch the artifact incrementally with str_replace. Never rewrite the whole thing.",
            ],
            shortcodes_and_layouts: [
              "Do not guess shortcode syntax. Use wp_find_content to find similar existing posts/pages on this site.",
              "Fetch their content with wp_get_content to learn the shortcode patterns in use.",
              "For Axtolab / Flatsome theme pages: clone an existing page with wp_clone_content and modify it rather than building from scratch.",
              "Always check live examples before writing complex shortcode structures.",
            ],
            images: [
              "Upload at ANY POINT — before drafting, during, or after. User decides timing.",
              "Local file: wp_find_media_file (filename) → wp_upload_media_from_path (file_path). Zero base64 in conversation.",
              "NOTE: Chat-uploaded files show a container path (e.g. /mnt/user-data/uploads/...) that the MCP cannot read. Use wp_find_media_file with the filename to locate on local filesystem.",
              "URL: wp_upload_media_from_url. Existing media: wp_search_media → show thumbnails visually.",
              "NEVER ask user for base64 or image data.",
            ],
            seo: [
              "Set yoast_wpseo_focuskw FIRST via wp_update_yoast_metadata — analysis returns null without it.",
              "Then set yoast_wpseo_title (SEO title) and yoast_wpseo_metadesc (120-155 chars).",
              "Run wp_get_yoast_analysis after setting focuskw to get scores.",
            ],
            publishing_checklist: [
              "ALWAYS ASK before publishing: 'Should I publish immediately, or schedule for a specific date and time? (e.g. next Monday at 9am AEST)'",
              "If scheduling: pass ISO 8601 UTC date in the 'date' parameter (e.g. '2026-03-01T23:00:00Z' for 9am AEST).",
              "Before publishing, verify metadata header is complete: slug ✅, categories ✅, tags ✅, featured image ✅, Yoast focuskw + metadesc + title ✅.",
              "Always call wp_get_preview_link and share the signed URL BEFORE publishing — user must see the real rendered result.",
            ],
          },
        };
      })
  );

  server.tool("wp_list_content_types", "List content types allowed for this MCP server.", {}, async () =>
    runToolWithLimit("wp_list_content_types", {}, async () => site().client.listContentTypes())
  );

  server.tool(
    "wp_find_content",
    "Full-text search, list, and filter content across allowed post types. Pass `search` for keyword search, plus optional filters for content type, status, author, taxonomy, and pagination. For pure keyword search, prefer `wp_search_content`.",
    {
      content_type: ContentTypeSchema.optional(),
      search: z.string().optional(),
      status: z.string().optional(),
      author: z.number().int().positive().optional(),
      page: z.number().int().positive().optional(),
      per_page: z.number().int().positive().max(100).optional(),
      parent_term_slug: z.string().optional(),
      parent_taxonomy: z.string().optional(),
      solutions_only: z.boolean().optional(),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_find_content", rawInput, async () => {
        if (typeof rawInput.content_type === "string") {
          site().policy.assertAllowedContentType(rawInput.content_type);
        }

        if (typeof rawInput.author === "number") {
          site().policy.assertAllowedAuthor(rawInput.author);
        }

        const results = await site().client.findContent(rawInput);
        // Strip full content field — use wp_get_content to fetch content for a specific post
        return results.map((item: ContentRecord) => {
          const { content: _content, ...rest } = item;
          return rest;
        });
      })
  );

  // wp_search_content is a thin alias over wp_find_content with `query` as a
  // required parameter. Discoverable for clients/users who look for "search"
  // rather than "find_content". Same handler, same WP_Query under the hood.
  server.tool(
    "wp_search_content",
    "Full-text search WordPress content by keyword. Returns matching posts/pages/CPTs across allowed post types. Use this when you have a search query in mind; use `wp_find_content` when you need filter-driven listing.",
    {
      query: z.string().min(1).describe("Keyword to search for (full-text, matches title and body)"),
      content_type: ContentTypeSchema.optional(),
      status: z.string().optional(),
      author: z.number().int().positive().optional(),
      page: z.number().int().positive().optional(),
      per_page: z.number().int().positive().max(100).optional(),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_search_content", rawInput, async () => {
        if (typeof rawInput.content_type === "string") {
          site().policy.assertAllowedContentType(rawInput.content_type);
        }

        if (typeof rawInput.author === "number") {
          site().policy.assertAllowedAuthor(rawInput.author);
        }

        // Map `query` to the `search` param the WP REST handler expects.
        const { query, ...rest } = rawInput as { query: string } & Record<string, unknown>;
        const findArgs = { ...rest, search: query };

        const results = await site().client.findContent(findArgs);
        return results.map((item: ContentRecord) => {
          const { content: _content, ...rest } = item;
          return rest;
        });
      })
  );

  // wp_list_abilities / wp_invoke_ability — Phase 4a (StifLi-parity).
  // Bridge to the official WP Abilities API (WP 6.9+, Nov 2025). Generic
  // dispatcher pattern: list to discover, invoke to execute by name. Each
  // ability runs through WP core's own permission_callback so security
  // posture is whatever the registering plugin configured.
  server.tool(
    "wp_list_abilities",
    "List abilities registered via the official WordPress Abilities API (WP 6.9+, Nov 2025). Returns name, label, description, input_schema, output_schema for each. If WP < 6.9, returns `available: false` with a reason. Other plugins can register abilities (their own admin functions, custom workflows, etc.) and AI agents call them via wp_invoke_ability without per-plugin tool authoring.",
    {},
    async () =>
      runToolWithLimit("wp_list_abilities", {}, async () => site().client.listAbilities())
  );

  server.tool(
    "wp_invoke_ability",
    "Execute a registered WP ability by name. Discover available abilities via wp_list_abilities first, then call this with the ability's `name` and `args` matching its input_schema. Each ability enforces its own capability check inside execute(); this tool just dispatches.",
    {
      name: z.string().min(1).describe("Ability name as returned by wp_list_abilities"),
      args: z.record(z.unknown()).optional().describe("Arguments matching the ability's input_schema"),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_invoke_ability", rawInput, async () =>
        site().client.invokeAbility(
          String(rawInput.name),
          (rawInput.args as Record<string, unknown>) ?? {}
        )
      )
  );

  // wp_get_active_theme / wp_get_theme_mods / wp_get_custom_css — read-only
  // theme inspection. Theme writes (theme_mod updates, Custom CSS) are out of
  // scope for this WP.org package per the directory's guidelines against
  // plugins that save arbitrary CSS/JS/PHP.
  server.tool(
    "wp_get_active_theme",
    "Read the active WordPress theme: name, stylesheet, template, version, author, parent (if child theme), description.",
    {},
    async () =>
      runToolWithLimit("wp_get_active_theme", {}, async () => site().client.getActiveTheme())
  );

  server.tool(
    "wp_get_theme_mods",
    "Read all theme mods (Customizer values) for the active theme. Sensitive-named keys (api_key/secret/token/etc.) are redacted to `[REDACTED]`.",
    {},
    async () =>
      runToolWithLimit("wp_get_theme_mods", {}, async () => site().client.getThemeMods())
  );

  server.tool(
    "wp_get_custom_css",
    "Read the active theme's Custom CSS (the same string saved in WP Customizer → Additional CSS).",
    {},
    async () =>
      runToolWithLimit("wp_get_custom_css", {}, async () => site().client.getCustomCss())
  );

  // wp_list_menus / wp_get_menu / wp_create_menu_item / wp_update_menu_item /
  // wp_delete_menu_item / wp_reorder_menu_items — Phase 2 #9 of Royal-parity.
  // Reads are authenticated; writes require edit_theme_options on the
  // connected user (enforced server-side with a clear 403 if missing).
  server.tool(
    "wp_list_menus",
    "List all WordPress nav menus (id, name, slug, item count). Read-only.",
    {},
    async () => runToolWithLimit("wp_list_menus", {}, async () => site().client.listMenus())
  );

  server.tool(
    "wp_get_menu",
    "Get one nav menu (by id or slug) with its items expanded. Returns id, name, slug, count, items[]. Each item has id, title, url, type, object, object_id, parent, menu_order, target, classes, attr_title, description, xfn.",
    {
      id_or_slug: z.union([z.string().min(1), z.number().int().positive()]).describe("Menu ID (number) or menu slug (string)"),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_get_menu", rawInput, async () =>
        site().client.getMenu(rawInput.id_or_slug as string | number)
      )
  );

  server.tool(
    "wp_create_menu_item",
    "Add an item to a nav menu. `type` is one of: custom (use `url`), post_type (use `object` + `object_id`), taxonomy (use `object` + `object_id`). `parent` is another menu item id (0 for top-level). Requires edit_theme_options.",
    {
      menu_id: z.number().int().positive(),
      title: z.string().min(1),
      url: z.string().optional(),
      type: z.enum(["custom", "post_type", "taxonomy"]).optional().default("custom"),
      object: z.string().optional().describe("Required for post_type (e.g. 'page', 'post') or taxonomy (e.g. 'category')"),
      object_id: z.number().int().nonnegative().optional(),
      parent: z.number().int().nonnegative().optional().default(0),
      target: z.enum(["", "_blank"]).optional(),
      classes: z.union([z.string(), z.array(z.string())]).optional(),
      attr_title: z.string().optional(),
      description: z.string().optional(),
      xfn: z.string().optional(),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_create_menu_item", rawInput, async () => {
        const { menu_id, ...item } = rawInput as { menu_id: number } & Record<string, unknown>;
        return site().client.createMenuItem(Number(menu_id), item);
      })
  );

  server.tool(
    "wp_update_menu_item",
    "Update an existing menu item. Pass any subset of the create fields (plus optional `menu_order`). Requires edit_theme_options.",
    {
      item_id: z.number().int().positive(),
      title: z.string().optional(),
      url: z.string().optional(),
      type: z.enum(["custom", "post_type", "taxonomy"]).optional(),
      object: z.string().optional(),
      object_id: z.number().int().nonnegative().optional(),
      parent: z.number().int().nonnegative().optional(),
      menu_order: z.number().int().optional(),
      target: z.enum(["", "_blank"]).optional(),
      classes: z.union([z.string(), z.array(z.string())]).optional(),
      attr_title: z.string().optional(),
      description: z.string().optional(),
      xfn: z.string().optional(),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_update_menu_item", rawInput, async () => {
        const { item_id, ...updates } = rawInput as { item_id: number } & Record<string, unknown>;
        return site().client.updateMenuItem(Number(item_id), updates);
      })
  );

  server.tool(
    "wp_delete_menu_item",
    "Delete a menu item by ID. Requires edit_theme_options.",
    {
      item_id: z.number().int().positive(),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_delete_menu_item", rawInput, async () =>
        site().client.deleteMenuItem(Number(rawInput.item_id))
      )
  );

  server.tool(
    "wp_reorder_menu_items",
    "Batch update menu_order and/or parent on multiple items in a menu. Pass `order: [{item_id, menu_order, parent?}, ...]`. Requires edit_theme_options.",
    {
      menu_id: z.number().int().positive(),
      order: z.array(z.object({
        item_id: z.number().int().positive(),
        menu_order: z.number().int().optional(),
        parent: z.number().int().nonnegative().optional(),
      })),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_reorder_menu_items", rawInput, async () =>
        site().client.reorderMenuItems(
          Number(rawInput.menu_id),
          (rawInput.order as Array<{ item_id: number; menu_order?: number; parent?: number }>) ?? []
        )
      )
  );

  // wp_get_seo_meta / wp_update_seo_meta — Phase 2 #8 of Royal-parity.
  // Provider-neutral SEO read/write. Auto-detects Yoast / Rank Math /
  // AIOSEO and routes to the active plugin's storage. The standardized
  // field set is title / description / focus_keyphrase / noindex /
  // nofollow / og_title / og_description / og_image / twitter_title /
  // twitter_description / twitter_image.
  //
  // The legacy Yoast-specific tools (wp_get_yoast_analysis,
  // wp_update_yoast_metadata, wp_get_yoast_head_preview) remain for
  // direct Yoast-specific behavior; new code should prefer these
  // generic tools.
  server.tool(
    "wp_get_seo_meta",
    "Read SEO metadata for a post in provider-neutral form. Auto-detects the active SEO plugin (Yoast / Rank Math / AIOSEO) and returns standardized fields: title, description, focus_keyphrase, noindex, nofollow, og_*, twitter_*. The `plugin` field in the response reports which plugin was detected. Returns null/empty fields if no supported SEO plugin is active.",
    {
      post_id: z.number().int().positive(),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_get_seo_meta", rawInput, async () =>
        site().client.getSeoMeta(Number(rawInput.post_id))
      )
  );

  server.tool(
    "wp_update_seo_meta",
    "Write SEO metadata for a post in provider-neutral form. Accepts any subset of the standardized fields (title, description, focus_keyphrase, noindex, nofollow, og_title, og_description, og_image, twitter_title, twitter_description, twitter_image) and routes to whichever SEO plugin is active. Booleans for noindex/nofollow accepted as `1` / `0` strings or true/false. Pass `id` as the path-derived post id.",
    {
      post_id: z.number().int().positive(),
      fields: z.record(z.unknown()).describe("Object of standardized SEO field names to values."),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_update_seo_meta", rawInput, async () =>
        site().client.updateSeoMeta(
          Number(rawInput.post_id),
          (rawInput.fields as Record<string, unknown>) ?? {}
        )
      )
  );

  // wp_get_option / wp_update_option / wp_get_plugin_settings —
  // Phase 1 #6 of the Royal-parity plan. Three-gate write security
  // matches Royal MCP's wp_update_option (admin toggle + allowlist filter
  // + hard denylist). Reads automatically redact sensitive keys
  // (api_key/secret/token/password/license/salt patterns).
  server.tool(
    "wp_get_option",
    "Read a single WordPress option by key. Sensitive-named options (api_key/secret/token/password/license/salt patterns) are returned as `[REDACTED]` — AI agents see configuration shape but never stored credentials.",
    {
      key: z.string().min(1).max(191).describe("Option key (e.g. `blogname`, `siteurl`, `axtolab_ai_connector_settings`)"),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_get_option", rawInput, async () =>
        site().client.getOption(String(rawInput.key))
      )
  );

  server.tool(
    "wp_update_option",
    "Write a WordPress option. Triple-gated for safety: (1) admin must enable `options_writes_enabled` in MCP Gateway settings; (2) requires manage_options capability; (3) key must be in the writable allowlist (default: `blogname`, `blogdescription`, `posts_per_page`, `date_format`, `time_format`, `start_of_week`) AND not match the hard denylist. Plugin authors can opt their settings in via the `axtolab_ai_connector_writable_options` PHP filter.",
    {
      key: z.string().min(1).max(191),
      value: z.unknown().describe("New value (any JSON-serializable type)"),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_update_option", rawInput, async () =>
        site().client.updateOption(String(rawInput.key), rawInput.value)
      )
  );

  server.tool(
    "wp_get_plugin_settings",
    "Read all WordPress options whose key matches a plugin slug prefix (`<slug>_*`, `<slug>-*`, or exact `<slug>`). Returns up to 200 options with sensitive keys auto-redacted. Useful for AI agents to understand a plugin's configuration without exposing stored API keys/secrets.",
    {
      slug: z.string().min(1).max(64).describe("Plugin slug (e.g. `wpforms`, `yoast`, `axtolab`)"),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_get_plugin_settings", rawInput, async () =>
        site().client.getPluginSettings(String(rawInput.slug))
      )
  );

  // wp_get_term_meta / wp_update_term_meta / wp_delete_term_meta —
  // Phase 1 #5 of the Royal-parity plan. Most common real-world use:
  // Yoast SEO / Rank Math / AIOSEO term-level SEO meta on categories/tags.
  server.tool(
    "wp_get_term_meta",
    "Read taxonomy term meta. Without `key`, returns all meta as a map; with `key`, returns just that meta value. Useful for reading Yoast SEO term metadata (`_yoast_wpseo_title`, `_yoast_wpseo_metadesc`), Rank Math (`rank_math_title`, `rank_math_description`), or AIOSEO term meta.",
    {
      term_id: z.number().int().positive(),
      key: z.string().optional().describe("Optional meta key. Omit to return all meta."),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_get_term_meta", rawInput, async () =>
        site().client.getTermMeta(Number(rawInput.term_id), rawInput.key ? String(rawInput.key) : undefined)
      )
  );

  server.tool(
    "wp_update_term_meta",
    "Write taxonomy term meta. Pass a `meta` object of {key: value} pairs to set; scalars stored as-is, arrays/objects JSON-encoded. Useful for updating Yoast/Rank Math/AIOSEO term-level SEO metadata.",
    {
      term_id: z.number().int().positive(),
      meta: z.record(z.unknown()).describe("Object of meta_key -> meta_value pairs"),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_update_term_meta", rawInput, async () =>
        site().client.updateTermMeta(
          Number(rawInput.term_id),
          (rawInput.meta as Record<string, unknown>) ?? {},
        )
      )
  );

  server.tool(
    "wp_delete_term_meta",
    "Delete a single taxonomy term meta key.",
    {
      term_id: z.number().int().positive(),
      meta_key: z.string().min(1).describe("Meta key to delete"),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_delete_term_meta", rawInput, async () =>
        site().client.deleteTermMeta(Number(rawInput.term_id), String(rawInput.meta_key))
      )
  );

  // wp_list_plugins / wp_list_themes — Phase 1 #3 of the Royal-parity plan.
  // Read-only inventory of installed plugins/themes. No install/activate;
  // those are security-heavy and AI Engine keeps them Pro-only.
  server.tool(
    "wp_list_plugins",
    "List all installed WordPress plugins with metadata (name, version, description, author, status). Returns a count plus an array. Useful for AI agents to understand what's running on the site before recommending integrations or troubleshooting.",
    {},
    async () =>
      runToolWithLimit("wp_list_plugins", {}, async () => site().client.listPlugins())
  );

  server.tool(
    "wp_list_themes",
    "List all installed WordPress themes with metadata (name, version, description, author, parent theme, active flag). Returns a count plus an array. Helps AI agents understand the visual/template stack — e.g. detecting Elementor/Divi/Bricks themes for shortcode-aware authoring.",
    {},
    async () =>
      runToolWithLimit("wp_list_themes", {}, async () => site().client.listThemes())
  );

  // wp_get_permalink_structure / wp_update_permalink_structure — Phase 1 #2
  // of the Royal-parity plan. Read is authenticated-only; update is gated
  // by a three-layer check on the server (admin toggle in plugin settings,
  // manage_options capability, structure validation).
  server.tool(
    "wp_get_permalink_structure",
    "Read the WordPress permalink structure (URL pattern for posts/pages). Returns the raw `structure` string (e.g. `/%postname%/`), a human-readable `type` label (plain / post_name / day_and_name / custom), category_base, tag_base, and home_url.",
    {},
    async () =>
      runToolWithLimit("wp_get_permalink_structure", {}, async () =>
        site().client.getPermalinkStructure()
      )
  );

  server.tool(
    "wp_update_permalink_structure",
    "Update the WordPress permalink structure. REQUIRES an administrator to enable permalink writes in MCP Gateway settings (off by default). Pass an empty string for plain permalinks, or a structure containing rewrite tags like `%postname%`, `%year%/%monthnum%/%postname%`, etc. Rewrite rules are flushed automatically after the change.",
    {
      structure: z
        .string()
        .describe("Permalink structure. Empty string for plain. Otherwise must contain at least one valid rewrite tag (%year%, %monthnum%, %day%, %postname%, %post_id%, %category%, %author%)."),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_update_permalink_structure", rawInput, async () => {
        const structure = typeof rawInput.structure === "string" ? rawInput.structure : "";
        return site().client.updatePermalinkStructure(structure);
      })
  );

  server.tool(
    "wp_get_content",
    [
      "Get a single content item with full edit context (content, title, excerpt, meta, Yoast fields).",
      "EDITING WORKFLOW — follow this every time a user asks to 'work on', 'edit', or 'update' a post/page:",
      "  1. Call this tool to fetch the current content.",
      "  2. Immediately create a MARKDOWN (.md) artifact in the conversation — show the title, key metadata, and the content body so the user can read and edit copy inline.",
      "  3. Use MARKDOWN format — NOT an HTML artifact. The artifact is for editing copy, not rendering layout.",
      "  4. After every mutation (wp_update_content, wp_insert_inline_image, wp_remove_inline_image, wp_replace_inline_image), regenerate/update the markdown artifact to stay in sync with WordPress.",
      "  5. SHORTCODES & LAYOUT: If the post uses shortcodes you don't recognise, use wp_find_content to find similar existing pages and fetch their content as examples — learn the pattern from live content rather than guessing.",
      "The artifact is the user's single source of truth. Never let it go stale.",
    ].join(" "),
    {
      id: z.number().int().positive(),
      content_type: ContentTypeSchema,
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_get_content", rawInput, async () => {
        site().policy.assertAllowedContentType(String(rawInput.content_type));
        return site().client.getContent(Number(rawInput.id));
      })
  );

  server.tool(
    "wp_create_draft",
    [
      "🛑 ARTIFACT-FIRST GATE: All content creation and editing happens in the conversation artifact first — NOT in WordPress.",
      "The artifact is the sole working space: all content edits, image uploads, metadata changes, and SEO are iterated in the artifact until the user is satisfied.",
      "Only call this tool when the user explicitly confirms the artifact is ready to be sent to WordPress (e.g. 'send it to draft', 'ready to push', 'looks good, create the draft').",
      "Do NOT call this tool just because the user said 'start' or 'go ahead' at the beginning — that is for drafting the artifact, not creating the WordPress draft.",
      "After calling this tool, the artifact remains the source of truth. If the user requests further changes, update the artifact first, then ASK: 'Should I also update the WordPress draft?' before calling wp_update_content.",
      "Once in WordPress, call wp_get_preview_link for a final visual check, then wp_publish_content when approved.",
    ].join(" "),
    {
      content_type: ContentTypeSchema,
      title: z.string().min(1),
      content: z.string().optional(),
      excerpt: z.string().optional(),
      content_format: z.enum(["html", "markdown", "auto"]).optional(),
      slug: z.string().optional(),
      author: z.number().int().positive().optional(),
      date: z.string().optional(),
      terms: z.record(z.array(z.number().int().positive())).optional(),
      yoast_meta: z.record(z.unknown()).optional(),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_create_draft", rawInput, async () => {
        const contentType = String(rawInput.content_type);
        site().policy.assertAllowedContentType(contentType);

        if (typeof rawInput.author === "number") {
          site().policy.assertAllowedAuthor(rawInput.author);
          const { allowedAuthorIds } = site();
          if (allowedAuthorIds !== null && !allowedAuthorIds.includes(rawInput.author)) {
            throw new ToolError('AUTHOR_RESTRICTED', `Author ID ${rawInput.author} is not permitted for this connection. Allowed author IDs: ${allowedAuthorIds.join(', ')}`);
          }
        }

        if (rawInput.terms && typeof rawInput.terms === "object") {
          for (const taxonomy of Object.keys(rawInput.terms as Record<string, unknown>)) {
            site().policy.assertAllowedTaxonomy(taxonomy);
          }
        }

        if (rawInput.yoast_meta && typeof rawInput.yoast_meta === "object") {
          site().policy.assertYoastMetaKeys(rawInput.yoast_meta as Record<string, unknown>);
        }

        const contentFormat = (rawInput.content_format as ContentFormat) || "auto";
        const processedInput = { ...rawInput, status: "draft" } as Record<string, unknown>;
        if (typeof processedInput.content === "string") {
          processedInput.content = toHtml(processedInput.content, contentFormat);
        }
        if (typeof processedInput.excerpt === "string" && processedInput.excerpt) {
          processedInput.excerpt = toHtml(processedInput.excerpt, contentFormat);
        }

        return site().client.createContent(processedInput);
      })
  );

  server.tool(
    "wp_update_content",
    "Patch an existing content item.",
    {
      id: z.number().int().positive(),
      content_type: ContentTypeSchema,
      patch: z.record(z.unknown()),
      content_format: z.enum(["html", "markdown", "auto"]).optional(),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_update_content", rawInput, async () => {
        const contentType = String(rawInput.content_type);
        site().policy.assertAllowedContentType(contentType);

        const patch = rawInput.patch as Record<string, unknown>;
        site().policy.assertPatchFields(patch);

        if (typeof patch.author === "number") {
          site().policy.assertAllowedAuthor(patch.author);
        }

        const contentFormat = (rawInput.content_format as ContentFormat) || "auto";
        if (typeof patch.content === "string") {
          patch.content = toHtml(patch.content, contentFormat);
        }
        if (typeof patch.excerpt === "string" && patch.excerpt) {
          patch.excerpt = toHtml(patch.excerpt, contentFormat);
        }

        const result = await site().client.updateContent(Number(rawInput.id), {
          content_type: contentType,
          patch,
        });
        // Strip content body — Claude already sent it; returning it wastes context window
        if (result && typeof result === "object") {
          const { content: _c, ...slim } = result as unknown as Record<string, unknown>;
          return slim;
        }
        return result;
      })
  );

  server.tool(
    "wp_publish_content",
    [
      "Publish or schedule content with confirmation flow.",
      "BEFORE calling this tool, always ask the user: 'Should I publish immediately, or would you like to schedule this for a specific date and time? (e.g. next Monday 9am — please include your timezone)'",
      "If scheduling, convert to ISO 8601 UTC and pass in the 'date' parameter (e.g. '2026-03-01T23:00:00Z' for 9am AEST/UTC+10).",
      "If publishing immediately, omit the 'date' parameter.",
      "A confirmation token is required — the system will prompt for it automatically on the first call.",
    ].join(" "),
    {
      id: z.number().int().positive(),
      content_type: ContentTypeSchema,
      date: z.string().optional(),
      confirmation_token: z.string().optional(),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_publish_content", rawInput, async () => {
        const id = Number(rawInput.id);
        const contentType = String(rawInput.content_type);
        site().policy.assertAllowedContentType(contentType);

        const key = `${contentType}:${id}:publish`;
        const gate = requireConfirmation(
          confirmations,
          "publish_content",
          key,
          rawInput,
          rawInput.confirmation_token as string | undefined
        );

        if (!gate.confirmed) {
          return gate.payload;
        }

        return site().client.publishContent(id, {
          content_type: contentType,
          date: rawInput.date,
        });
      })
  );

  server.tool(
    "wp_trash_content",
    "Move content to trash (confirmation required).",
    {
      id: z.number().int().positive(),
      content_type: ContentTypeSchema,
      confirmation_token: z.string().optional(),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_trash_content", rawInput, async () => {
        const id = Number(rawInput.id);
        const contentType = String(rawInput.content_type);
        site().policy.assertAllowedContentType(contentType);

        const key = `${contentType}:${id}:trash`;
        const gate = requireConfirmation(
          confirmations,
          "trash_content",
          key,
          rawInput,
          rawInput.confirmation_token as string | undefined
        );

        if (!gate.confirmed) {
          return gate.payload;
        }

        return site().client.trashContent(id);
      })
  );

  server.tool(
    "wp_restore_content",
    "Restore content from trash (confirmation required).",
    {
      id: z.number().int().positive(),
      content_type: ContentTypeSchema,
      confirmation_token: z.string().optional(),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_restore_content", rawInput, async () => {
        const id = Number(rawInput.id);
        const contentType = String(rawInput.content_type);
        site().policy.assertAllowedContentType(contentType);

        const key = `${contentType}:${id}:restore`;
        const gate = requireConfirmation(
          confirmations,
          "restore_content",
          key,
          rawInput,
          rawInput.confirmation_token as string | undefined
        );

        if (!gate.confirmed) {
          return gate.payload;
        }

        return site().client.restoreContent(id);
      })
  );

  server.tool(
    "wp_list_revisions",
    "List revisions for a content item.",
    {
      id: z.number().int().positive(),
      content_type: ContentTypeSchema,
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_list_revisions", rawInput, async () => {
        site().policy.assertAllowedContentType(String(rawInput.content_type));
        return site().client.listRevisions(Number(rawInput.id));
      })
  );

  server.tool(
    "wp_restore_revision",
    "Restore a specific revision (confirmation required).",
    {
      id: z.number().int().positive(),
      revision_id: z.number().int().positive(),
      content_type: ContentTypeSchema,
      confirmation_token: z.string().optional(),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_restore_revision", rawInput, async () => {
        const id = Number(rawInput.id);
        const revisionId = Number(rawInput.revision_id);
        const contentType = String(rawInput.content_type);
        site().policy.assertAllowedContentType(contentType);

        const key = `${contentType}:${id}:revision:${revisionId}`;
        const gate = requireConfirmation(
          confirmations,
          "restore_revision",
          key,
          rawInput,
          rawInput.confirmation_token as string | undefined
        );

        if (!gate.confirmed) {
          return gate.payload;
        }

        return site().client.restoreRevision(id, revisionId);
      })
  );

  server.tool("wp_list_authors", "List allowlisted authors.", {}, async () =>
    runToolWithLimit("wp_list_authors", {}, async () => site().client.listAuthors())
  );

  server.tool(
    "wp_assign_author",
    "Assign an allowlisted author to content.",
    {
      id: z.number().int().positive(),
      content_type: ContentTypeSchema,
      author_id: z.number().int().positive(),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_assign_author", rawInput, async () => {
        site().policy.assertAllowedContentType(String(rawInput.content_type));
        const authorId = Number(rawInput.author_id);
        site().policy.assertAllowedAuthor(authorId);
        const { allowedAuthorIds } = site();
        if (allowedAuthorIds !== null && !allowedAuthorIds.includes(authorId)) {
          throw new ToolError('AUTHOR_RESTRICTED', `Author ID ${authorId} is not permitted for this connection. Allowed author IDs: ${allowedAuthorIds.join(', ')}`);
        }
        return site().client.assignAuthor(Number(rawInput.id), authorId);
      })
  );

  server.tool(
    "wp_list_terms",
    "List terms for an allowlisted taxonomy.",
    {
      taxonomy: z.string().min(1),
      search: z.string().optional(),
      parent: z.number().int().min(0).optional(),
      page: z.number().int().positive().optional(),
      per_page: z.number().int().positive().max(100).optional(),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_list_terms", rawInput, async () => {
        const taxonomy = String(rawInput.taxonomy);
        site().policy.assertAllowedTaxonomy(taxonomy);
        return site().client.listTerms(taxonomy, rawInput);
      })
  );

  server.tool(
    "wp_create_term",
    "Create a term in an allowlisted taxonomy.",
    {
      taxonomy: z.string().min(1),
      name: z.string().min(1),
      slug: z.string().optional(),
      parent: z.number().int().min(0).optional(),
      description: z.string().optional(),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_create_term", rawInput, async () => {
        const taxonomy = String(rawInput.taxonomy);
        site().policy.assertAllowedTaxonomy(taxonomy);
        return site().client.createTerm(taxonomy, rawInput);
      })
  );

  server.tool(
    "wp_assign_terms",
    "Assign term IDs to a content item.",
    {
      id: z.number().int().positive(),
      content_type: ContentTypeSchema,
      terms: z.record(z.array(z.number().int().positive())),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_assign_terms", rawInput, async () => {
        site().policy.assertAllowedContentType(String(rawInput.content_type));
        const terms = rawInput.terms as Record<string, number[]>;

        for (const taxonomy of Object.keys(terms)) {
          site().policy.assertAllowedTaxonomy(taxonomy);
        }

        return site().client.assignTerms(Number(rawInput.id), {
          content_type: rawInput.content_type,
          terms,
        });
      })
  );

  server.tool(
    "wp_search_media",
    "Search media library.",
    {
      search: z.string().optional(),
      page: z.number().int().positive().optional(),
      per_page: z.number().int().positive().max(100).optional(),
      mime_type: z.string().optional(),
    },
    async (rawInput: Record<string, unknown>) => runToolWithLimit("wp_search_media", rawInput, async () => site().client.searchMedia(rawInput))
  );

  server.tool(
    "wp_update_media",
    "Update media metadata.",
    {
      id: z.number().int().positive(),
      alt_text: z.string().optional(),
      caption: z.string().optional(),
      description: z.string().optional(),
      title: z.string().optional(),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_update_media", rawInput, async () => site().client.updateMedia(Number(rawInput.id), rawInput))
  );

  server.tool(
    "wp_set_featured_image",
    "Set or remove a featured image on content.",
    {
      id: z.number().int().positive(),
      content_type: ContentTypeSchema,
      media_id: z.number().int().positive().nullable(),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_set_featured_image", rawInput, async () => {
        site().policy.assertAllowedContentType(String(rawInput.content_type));
        return site().client.setFeaturedImage(Number(rawInput.id), rawInput.media_id as number | null);
      })
  );

  server.tool(
    "wp_insert_inline_image",
    "Insert an inline image into content in block-aware mode with HTML fallback.",
    {
      id: z.number().int().positive(),
      content_type: ContentTypeSchema,
      media_id: z.number().int().positive(),
      placement: PlacementSchema,
      heading_text: z.string().optional(),
      marker: z.string().optional(),
      align: z.enum(["none", "left", "center", "right", "wide", "full"]).optional(),
      size_slug: z.string().optional(),
      caption: z.string().optional(),
      alt_text: z.string().optional(),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_insert_inline_image", rawInput, async () => {
        site().policy.assertAllowedContentType(String(rawInput.content_type));
        const result = await site().client.insertInlineImage(Number(rawInput.id), rawInput);
        if (result && typeof result === "object") {
          const { content: _c, ...slim } = result as unknown as Record<string, unknown>;
          return slim;
        }
        return result;
      })
  );

  server.tool(
    "wp_replace_inline_image",
    "Replace an inline image reference in content.",
    {
      id: z.number().int().positive(),
      content_type: ContentTypeSchema,
      new_media_id: z.number().int().positive(),
      match_media_id: z.number().int().positive().optional(),
      match_src_substring: z.string().optional(),
      alt_text: z.string().optional(),
      caption: z.string().optional(),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_replace_inline_image", rawInput, async () => {
        site().policy.assertAllowedContentType(String(rawInput.content_type));

        if (!rawInput.match_media_id && !rawInput.match_src_substring) {
          throw new ToolError(
            "INLINE_MATCH_REQUIRED",
            "Either match_media_id or match_src_substring must be provided"
          );
        }

        const result = await site().client.replaceInlineImage(Number(rawInput.id), rawInput);
        if (result && typeof result === "object") {
          const { content: _c, ...slim } = result as unknown as Record<string, unknown>;
          return slim;
        }
        return result;
      })
  );

  server.tool(
    "wp_remove_inline_image",
    "Remove an inline image from content.",
    {
      id: z.number().int().positive(),
      content_type: ContentTypeSchema,
      match_media_id: z.number().int().positive().optional(),
      match_src_substring: z.string().optional(),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_remove_inline_image", rawInput, async () => {
        site().policy.assertAllowedContentType(String(rawInput.content_type));

        if (!rawInput.match_media_id && !rawInput.match_src_substring) {
          throw new ToolError(
            "INLINE_MATCH_REQUIRED",
            "Either match_media_id or match_src_substring must be provided"
          );
        }

        const result = await site().client.removeInlineImage(Number(rawInput.id), rawInput);
        if (result && typeof result === "object") {
          const { content: _c, ...slim } = result as unknown as Record<string, unknown>;
          return slim;
        }
        return result;
      })
  );

  server.tool(
    "wp_get_yoast_analysis",
    [
      "Get Yoast readability and SEO analysis for content.",
      "IMPORTANT: Many scores (including SEO score) will return null or 'N/A' if no focus keyphrase (yoast_wpseo_focuskw) has been set.",
      "Always set the focus keyphrase first using wp_update_yoast_metadata with key 'yoast_wpseo_focuskw' BEFORE calling this tool.",
      "If the user hasn't specified a focus keyphrase, suggest one based on the post title and main topic, confirm with the user, then set it before running analysis.",
    ].join(" "),
    {
      id: z.number().int().positive(),
      content_type: ContentTypeSchema,
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_get_yoast_analysis", rawInput, async () => {
        site().policy.assertAllowedContentType(String(rawInput.content_type));
        site().policy.assertYoastPath("/yoast/analysis");
        return site().client.getYoastAnalysis(Number(rawInput.id));
      })
  );

  server.tool(
    "wp_update_yoast_metadata",
    [
      "Update allowlisted Yoast metadata for content.",
      "IMPORTANT: Use the full yoast_wpseo_* key names — NOT short aliases.",
      "Allowed keys: yoast_wpseo_title (SEO title), yoast_wpseo_metadesc (meta description),",
      "yoast_wpseo_focuskw (focus keyphrase), yoast_wpseo_canonical,",
      "yoast_wpseo_opengraph-title, yoast_wpseo_opengraph-description,",
      "yoast_wpseo_twitter-title, yoast_wpseo_twitter-description.",
      "Example: { yoast_meta: { yoast_wpseo_title: 'My SEO Title', yoast_wpseo_focuskw: 'industrial IoT', yoast_wpseo_metadesc: 'Description here.' } }",
    ].join(" "),
    {
      id: z.number().int().positive(),
      content_type: ContentTypeSchema,
      yoast_meta: z.record(z.unknown()),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_update_yoast_metadata", rawInput, async () => {
        site().policy.assertAllowedContentType(String(rawInput.content_type));
        site().policy.assertYoastPath("/yoast/metadata");
        site().policy.assertYoastMetaKeys(rawInput.yoast_meta as Record<string, unknown>);
        return site().client.updateYoastMetadata(Number(rawInput.id), {
          content_type: rawInput.content_type,
          yoast_meta: rawInput.yoast_meta,
        });
      })
  );

  server.tool(
    "wp_get_yoast_head_preview",
    "Get Yoast head/meta preview payload.",
    {
      id: z.number().int().positive(),
      content_type: ContentTypeSchema,
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_get_yoast_head_preview", rawInput, async () => {
        site().policy.assertAllowedContentType(String(rawInput.content_type));
        site().policy.assertYoastPath("/yoast/head");
        return site().client.getYoastHeadPreview(Number(rawInput.id));
      })
  );

  server.tool(
    "wp_get_preview_link",
    "Return both WordPress preview and signed shareable preview URLs.",
    {
      id: z.number().int().positive(),
      content_type: ContentTypeSchema,
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_get_preview_link", rawInput, async () => {
        site().policy.assertAllowedContentType(String(rawInput.content_type));
        return site().client.getPreviewLink(Number(rawInput.id));
      })
  );

  // ── Post Meta / Custom Fields ─────────────────────────────────────────

  server.tool(
    "wp_get_post_meta",
    "Read custom fields (post meta) for a post. Returns all meta or a single key. Works with ACF, MetaBox, JetEngine, Pods, and any plugin that stores data as post meta.",
    {
      id: z.number().int().positive().describe("Post ID"),
      key: z.string().optional().describe("Specific meta key to read. Omit to get all meta."),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_get_post_meta", rawInput, async () =>
        site().client.getPostMeta(Number(rawInput.id), rawInput.key as string | undefined)
      )
  );

  server.tool(
    "wp_update_post_meta",
    "Write custom fields (post meta) for a post. Pass key-value pairs to create or update. Works with ACF, MetaBox, and any plugin that uses post meta.",
    {
      id: z.number().int().positive().describe("Post ID"),
      meta: z.record(z.unknown()).describe("Key-value pairs of meta to set"),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_update_post_meta", rawInput, async () =>
        site().client.updatePostMeta(Number(rawInput.id), rawInput.meta as Record<string, unknown>)
      )
  );

  server.tool(
    "wp_delete_post_meta",
    "Delete a custom field (post meta key) from a post.",
    {
      id: z.number().int().positive().describe("Post ID"),
      key: z.string().min(1).describe("Meta key to delete"),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_delete_post_meta", rawInput, async () =>
        site().client.deletePostMeta(Number(rawInput.id), String(rawInput.key))
      )
  );

  // ── Comments ────────────────────────────────────────────────────────

  server.tool(
    "wp_list_comments",
    "List comments with optional filters: by post ID, by status (approve / hold / spam / trash / all). Use status='hold' for pending moderation, status='spam' for spam queue, etc. For the common 'show me pending comments' question prefer wp_list_pending_comments.",
    {
      post_id: z.number().int().positive().optional().describe("Filter by post ID"),
      status: z.enum(["approve", "hold", "spam", "trash", "all"]).optional().describe("Filter by comment status: approve (visible), hold (pending), spam, trash, all"),
      per_page: z.number().int().min(1).max(100).optional(),
      offset: z.number().int().min(0).optional(),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_list_comments", rawInput, async () =>
        site().client.listComments(rawInput)
      )
  );

  // wp_list_pending_comments — discoverable alias for the most common
  // moderation use case ("show me what needs my approval"). Same handler
  // as wp_list_comments with status='hold'. Phase 1 #4 of Royal-parity.
  server.tool(
    "wp_list_pending_comments",
    "List comments awaiting moderation (status='hold'). Convenience wrapper around wp_list_comments for the common 'show me pending' moderation use case.",
    {
      post_id: z.number().int().positive().optional().describe("Optional: only pending comments on this post"),
      per_page: z.number().int().min(1).max(100).optional(),
      offset: z.number().int().min(0).optional(),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_list_pending_comments", rawInput, async () => {
        const args = { ...rawInput, status: "hold" };
        return site().client.listComments(args);
      })
  );

  server.tool(
    "wp_get_comment",
    "Get a single comment by ID.",
    {
      id: z.number().int().positive(),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_get_comment", rawInput, async () =>
        site().client.getComment(Number(rawInput.id))
      )
  );

  server.tool(
    "wp_create_comment",
    "Create a comment on a post.",
    {
      post_id: z.number().int().positive().describe("Post to comment on"),
      content: z.string().min(1).describe("Comment text"),
      author: z.string().optional().describe("Author name (defaults to service account)"),
      parent: z.number().int().optional().describe("Parent comment ID for replies"),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_create_comment", rawInput, async () =>
        site().client.createComment(rawInput)
      )
  );

  server.tool(
    "wp_delete_comment",
    "Permanently delete a comment.",
    {
      id: z.number().int().positive(),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_delete_comment", rawInput, async () =>
        site().client.deleteComment(Number(rawInput.id))
      )
  );

  server.tool(
    "wp_moderate_comment",
    "Moderate a comment by ID. Action must be one of: approve (publish/show), hold (move to pending), spam (mark as spam and remove from view), trash (move to trash). Requires moderate_comments capability on the connection's WordPress role.",
    {
      id: z.number().int().positive(),
      action: z.enum(["approve", "hold", "spam", "trash"]).describe("Moderation action"),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_moderate_comment", rawInput, async () =>
        site().client.moderateComment(Number(rawInput.id), String(rawInput.action))
      )
  );

  // ── Media ───────────────────────────────────────────────────────────

  server.tool(
    "wp_get_media",
    "Get a single media item by ID with full metadata (dimensions, URLs, alt text).",
    {
      id: z.number().int().positive(),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_get_media", rawInput, async () => {
        return site().client.getMedia(Number(rawInput.id));
      })
  );

  server.tool(
    "wp_upload_media_from_url",
    "Upload media to WordPress by fetching from a URL (server-side download, no base64 needed).",
    {
      url: z.string().url(),
      alt_text: z.string().optional(),
      title: z.string().optional(),
      caption: z.string().optional(),
      description: z.string().optional(),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_upload_media_from_url", rawInput, async () => {
        return site().client.uploadMediaFromUrl(rawInput);
      })
  );

  // ─── Local Filesystem Image Tools ────────────────────────────────────────

  server.tool(
    "wp_find_media_file",
    [
      "Search the local filesystem for an image file by filename.",
      "Use this when the user mentions an image by name (e.g. 'hero.jpg') or drops a file in the chat — Claude Desktop exposes the image visually but does NOT expose the file path.",
      "CONTAINER PATH WARNING: When a user drags/drops or attaches a file in Claude Desktop, the path shown (e.g. /mnt/user-data/uploads/hero.jpg) is an internal container path — the MCP server CANNOT access it. Use this tool to find the real file on the user's local disk instead.",
      "Searches in order: current directory, any folder the user provides, then ~/Downloads, ~/Desktop, ~/Documents, ~/Pictures, and finally a shallow recursive scan of the home directory.",
      "If found, returns the absolute file_path to pass to wp_upload_media_from_path.",
      "If not found, ask the user: 'Could you share the folder where your images are stored? e.g. ~/Downloads or /Users/name/Projects/images'",
      "NEVER ask the user to paste base64 or re-upload the image — just find it on disk.",
    ].join(" "),
    {
      filename: z.string().min(1).describe("The image filename to search for, e.g. hero.jpg or banner.png"),
      folder: z.string().optional().describe("Optional absolute or ~ path to search first, e.g. ~/Downloads/project-images"),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_find_media_file", rawInput, async () => {
        const filename = String(rawInput.filename);
        const extraDirs: string[] = [];
        if (rawInput.folder) {
          extraDirs.push(SessionImageStore.expandPath(String(rawInput.folder)));
        }
        const { filePath, searchedDirs, closestMatches } = SessionImageStore.findFile(filename, extraDirs);
        if (filePath) {
          return {
            found: true,
            file_path: filePath,
            message: `Found "${filename}" at ${filePath}. Pass file_path to wp_upload_media_from_path to upload it to WordPress.`,
          };
        }
        const diag = [
          `Could not find "${filename}" (tried original name plus variants: spaces↔underscores, stripped parentheses, lowercase).`,
          `Searched ${searchedDirs.length} director${searchedDirs.length === 1 ? "y" : "ies"}: ${searchedDirs.slice(0, 6).join(", ")}${searchedDirs.length > 6 ? ` … and ${searchedDirs.length - 6} more` : ""}.`,
          closestMatches.length > 0
            ? `Closest partial matches: ${closestMatches.map((p: string) => `"${path.basename(p)}" at ${p}`).join("; ")}. Ask the user if one of these is the intended file.`
            : `No partial matches found.`,
          `Next step: ask the user "Could you share the folder where your images are stored? e.g. ~/Downloads/project-images" then retry with the folder param.`,
        ].join(" ");
        return {
          found: false,
          file_path: null,
          searched_dirs: searchedDirs,
          closest_matches: closestMatches,
          message: diag,
        };
      })
  );

  server.tool(
    "wp_upload_media_from_path",
    [
      "Upload an image to WordPress by reading it directly from the local filesystem.",
      "The MCP server runs on the user's local machine so it can read any local file path.",
      "Use the file_path returned by wp_find_media_file, or a path the user provides directly (e.g. ~/Downloads/hero.jpg).",
      "The image bytes are read from disk inside the MCP server and sent to WordPress — base64 is NEVER passed through the conversation context.",
      "Returns the full WordPress media record including media_id, source_url, and thumbnail_url.",
    ].join(" "),
    {
      file_path: z.string().min(1).describe("Absolute or ~ local file path. Use the result from wp_find_media_file, or a user-provided path such as ~/Downloads/hero.jpg"),
      alt_text: z.string().optional(),
      caption: z.string().optional(),
      description: z.string().optional(),
      title: z.string().optional(),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_upload_media_from_path", rawInput, async () => {
        const expanded = SessionImageStore.expandPath(String(rawInput.file_path));
        const { base64, filename, mimeType } = sessionImageStore.readImage(expanded);

        // Policy check using the decoded byte length
        const sizeBytes = Buffer.from(base64, "base64").byteLength;
        site().policy.assertMediaPolicy(mimeType, sizeBytes, rawInput.alt_text as string | undefined);

        const meta: Record<string, unknown> = {};
        if (rawInput.alt_text) meta.alt_text = rawInput.alt_text;
        if (rawInput.caption) meta.caption = rawInput.caption;
        if (rawInput.description) meta.description = rawInput.description;
        if (rawInput.title) meta.title = rawInput.title;

        return site().client.uploadMediaFromPath(expanded, base64, filename, mimeType, meta);
      })
  );

  server.tool(
    "wp_clone_content",
    "Clone an existing post or page as a new draft. Copies content, taxonomies, featured image, and Yoast meta.",
    {
      id: z.number().int().positive(),
      content_type: ContentTypeSchema,
      title: z.string().optional(),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_clone_content", rawInput, async () => {
        site().policy.assertAllowedContentType(String(rawInput.content_type));
        return site().client.cloneContent(Number(rawInput.id), rawInput);
      })
  );

  server.tool(
    "wp_request_review",
    "Send a review-request email notification to the site editor/admin, letting them know a draft is ready to publish. Use this when you have finished preparing a draft and the connection does not have publish permission.",
    {
      id: z.number().int().positive().describe("Post ID of the draft to review"),
      note: z.string().optional().describe("Optional message to include in the notification email, e.g. 'Images need final approval before publishing.'"),
    },
    async (rawInput: Record<string, unknown>) =>
      runToolWithLimit("wp_request_review", rawInput, async () => {
        const result = await site().client.requestReview(
          Number(rawInput.id),
          rawInput.note ? String(rawInput.note) : undefined
        );
        return {
          success: true,
          message: `Review request sent to ${result.sent_to}`,
          post_id: result.post_id,
          post_title: result.post_title,
        };
      })
  );

  // ── Image Providers ───────────────────────────────────────────────────

  generateImageTool = server.tool(
    "wp_generate_image",
    "Generate an image using AI (Google Imagen or OpenAI) and save it to the WordPress media library. The image is generated server-side — no image data passes through the conversation. Returns the media ID and URL so you can show the preview inline and then use wp_set_featured_image or wp_insert_inline_image. TIP: Help the user refine the prompt before generating. Show the result URL inline as a markdown image for preview.",
    {
      prompt: z.string().describe("Detailed image description. Be specific about style, composition, lighting."),
      provider: z.enum(["google_imagen", "openai"]).optional().describe("Provider. Defaults to first enabled."),
      aspect_ratio: z.enum(["1:1", "16:9", "9:16", "4:3", "3:4"]).optional().describe("Aspect ratio. Default: 16:9."),
      quality: z.enum(["low", "medium", "high"]).optional().describe("Quality tier (affects OpenAI cost). Default: medium."),
    },
    async (input: Record<string, unknown>) =>
      runToolWithLimit("wp_generate_image", input, async () =>
        site().client.generateImage(input)
      )
  );
  syncImageGenerationToolVisibility();

  server.tool(
    "wp_search_stock_photos",
    "Search for free stock photos from Unsplash or Pexels. Returns preview URLs you can display inline as markdown images for the user to choose from. Once they pick one, call wp_import_stock_photo.",
    {
      query: z.string().describe("Search terms."),
      provider: z.enum(["unsplash", "pexels"]).optional().describe("Stock provider. Defaults to first enabled."),
      orientation: z.enum(["landscape", "portrait", "square"]).optional().describe("Orientation filter."),
      per_page: z.number().int().min(1).max(10).optional().describe("Results count (1-10). Default: 5."),
    },
    async (input: Record<string, unknown>) =>
      runToolWithLimit("wp_search_stock_photos", input, async () =>
        site().client.searchStockPhotos(input)
      )
  );

  server.tool(
    "wp_import_stock_photo",
    "Import a stock photo into the WordPress media library. Use provider + provider_id from wp_search_stock_photos results. Handles attribution automatically.",
    {
      provider: z.enum(["unsplash", "pexels"]).describe("Stock provider."),
      provider_id: z.string().describe("Photo ID from search results."),
      alt_text: z.string().optional().describe("Override alt text."),
    },
    async (input: Record<string, unknown>) =>
      runToolWithLimit("wp_import_stock_photo", input, async () =>
        site().client.importStockPhoto(input)
      )
  );

  server.tool(
    "wp_list_image_providers",
    "List which image generation and stock photo providers are configured and enabled on this site.",
    {},
    async () =>
      runToolWithLimit("wp_list_image_providers", {}, async () =>
        site().client.listImageProviders()
      )
  );

  server.tool(
    "wp_confirm_image",
    "Confirm a generated image after user approval. Prevents auto-cleanup. Call this when the user says they want to keep/use a generated image.",
    {
      media_id: z.number().int().describe("Media ID of the generated image."),
    },
    async (input: Record<string, unknown>) =>
      runToolWithLimit("wp_confirm_image", input, async () =>
        site().client.confirmImage(Number(input.media_id))
      )
  );

  // ── Upload Portal ───────────────────────────────────────────────────

  server.tool(
    "wp_create_upload_session",
    "Create a temporary upload portal link for the user to drag-and-drop files " +
    "into the WordPress media library. The link expires in 15 minutes. Present " +
    "the URL to the user as a clickable link. This works for ALL clients. " +
    "For quick single-file uploads on Claude Desktop/Cowork, wp_upload_media_from_path " +
    "may be simpler. After the user uploads files, call wp_get_upload_session.",
    {
      ip_binding: z.boolean().optional().describe("Lock session to creator's IP for extra security"),
    },
    async (input: Record<string, unknown>) =>
      runToolWithLimit("wp_create_upload_session", input, async () =>
        site().client.createUploadSession({ ip_binding: input.ip_binding as boolean | undefined })
      )
  );

  server.tool(
    "wp_get_upload_session",
    "Check the status of an upload session and retrieve uploaded file details " +
    "(media IDs, URLs, filenames). Call this after the user confirms they've " +
    "finished uploading via the portal.",
    {
      session_id: z.string().describe("Session ID from wp_create_upload_session"),
    },
    async (input: Record<string, unknown>) =>
      runToolWithLimit("wp_get_upload_session", input, async () =>
        site().client.getUploadSession(String(input.session_id))
      )
  );

  // ── Site Management ──────────────────────────────────────────────────

  server.tool(
    "wp_connect_site",
    "Connect a new WordPress site using a connection token (wmcp1_...). " +
      "The token is generated in the WordPress admin under Axtolab AI Connector → Connection Token. " +
      "After connecting, the site is immediately available — no restart required.",
    {
      token: z.string().min(1).describe("The connection token starting with wmcp1_"),
    },
    async (input: Record<string, unknown>) =>
      runToolWithLimit("wp_connect_site", input, async () => {
        const tokenString = String(input.token).trim();

        const { decodeConnectionToken, isConnectionToken } = await import("../tokenDecoder.js");
        const { saveCredentials } = await import("../tokenStore.js");
        const { PluginApiClient } = await import("../client/pluginApiClient.js");
        const { PolicyService } = await import("../services/policyService.js");

        if (!isConnectionToken(tokenString)) {
          throw new ToolError("INVALID_TOKEN", "Not a valid connection token. Token must start with wmcp1_");
        }

        const payload = decodeConnectionToken(tokenString);

        // Extract hostname.
        let hostname: string;
        try {
          hostname = new URL(payload.site_url).hostname;
        } catch {
          hostname = payload.site_url.replace(/^https?:\/\//i, "").split("/")[0];
        }

        const existed = siteManager.hasSite(hostname);

        // Save to credential store on disk.
        await saveCredentials(hostname, {
          siteUrl: payload.site_url,
          wpPluginBaseUrl: payload.base_url,
          username: payload.username,
          appPassword: payload.token,
          siteName: payload.site_name,
          connectedAt: new Date().toISOString(),
        });

        // Hot-add to the running SiteManager so it's usable immediately.
        const env = process.env;
        const splitList = (v: string | undefined) =>
          v ? v.split(",").map((s: string) => s.trim()).filter(Boolean) : [];

        const newConfig = {
          wpPluginBaseUrl: payload.base_url,
          username: payload.username,
          appPassword: payload.token,
          siteName: payload.site_name,
          allowedContentTypes: splitList(env["WP_ALLOWED_CONTENT_TYPES"]),
          allowedTaxonomies: splitList(env["WP_ALLOWED_TAXONOMIES"]),
          allowedAuthors: splitList(env["WP_ALLOWED_AUTHORS"]),
          allowedMediaTypes: splitList(env["WP_ALLOWED_MEDIA_TYPES"]),
          allowedYoastPaths: splitList(env["WP_ALLOWED_YOAST_PATHS"]),
          rateLimitTokens: 60,
          rateLimitRefillRate: 1,
          httpTimeout: 30_000,
        };

        const newClient = new PluginApiClient(newConfig);

        let newAllowedTools: string[] | null = null;
        let newAllowedAuthorIds: number[] | null = null;
        let connectionCapabilityError: { code: string; message: string } | null = null;
        try {
          const capsResponse = await newClient.getConnectionCapabilities();
          newAllowedTools = capsResponse.allowed_tools;
          newAllowedAuthorIds = capsResponse.allowed_author_ids ?? null;
        } catch (error) {
          if (error instanceof ToolError && error.code === 'free_multisite_disabled') {
            newAllowedTools = [];
            newAllowedAuthorIds = null;
            connectionCapabilityError = { code: error.code, message: error.message };
          } else {
            // Older plugin versions don't have this endpoint — treat as no restriction
            newAllowedTools = null;
            newAllowedAuthorIds = null;
          }
        }

        siteManager.addSite(hostname, {
          config: newConfig,
          client: newClient,
          policy: new PolicyService(newConfig),
          allowedTools: newAllowedTools,
          allowedAuthorIds: newAllowedAuthorIds,
          connectionCapabilityError,
        });

        if (siteManager.getCurrentHostname() === hostname) {
          syncToolVisibility();
        }

        return {
          connected: true,
          hostname,
          site_name: payload.site_name,
          total_sites: siteManager.listSites().length,
          message: existed
            ? `Updated credentials for ${payload.site_name} (${hostname}). Use wp_switch_site to activate it.`
            : `Connected to ${payload.site_name} (${hostname})! Use wp_switch_site to activate it, or wp_list_sites to see all sites.`,
        };
      })
  );

  server.tool(
    "wp_list_sites",
    "List all connected WordPress sites and indicate which is currently active. Use wp_switch_site to change the active site.",
    {},
    async () =>
      runToolWithLimit("wp_list_sites", {}, async () => {
        const sites = siteManager.listSites();
        return {
          sites,
          multi_site_enabled: siteManager.isMultiSite(),
          hint: siteManager.isMultiSite()
            ? "Use wp_switch_site with a hostname to change the active site."
            : "Only one site is connected. Use wp_connect_site with a connection token to add more.",
        };
      })
  );

  server.tool(
    "wp_switch_site",
    "Switch the active WordPress site. All subsequent tool calls will target the new site. Use wp_list_sites to see available sites.",
    {
      hostname: z.string().min(1).describe("Hostname of the site to switch to (e.g. 'axtolab.com')"),
    },
    async (input: Record<string, unknown>) =>
      runToolWithLimit("wp_switch_site", input, async () => {
        const hostname = String(input.hostname);
        siteManager.switchTo(hostname);
        syncToolVisibility();
        const { config } = siteManager.getCurrent();
        return {
          switched: true,
          active_site: hostname,
          site_name: config.siteName || hostname,
          message: `Switched to ${config.siteName || hostname}. All WordPress tools now target this site.`,
        };
      })
  );

  syncToolVisibility();
}
