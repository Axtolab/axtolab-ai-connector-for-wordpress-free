import { describe, expect, it } from "vitest";
import { ToolConsentPolicy } from "../src/services/toolConsentPolicy.js";

describe("ToolConsentPolicy", () => {
  it("defaults destructive content actions to ask", () => {
    const policy = ToolConsentPolicy.defaults();
    const context = ToolConsentPolicy.contextForTool(
      "wp_delete_content",
      { id: 123, content_type: "post" },
      policy
    );

    expect(context.action).toBe("delete_content");
    expect(context.key).toBe("post:123:delete");
    expect(context.tier).toBe("ask");
  });

  it("honors always and disallow overrides", () => {
    const alwaysPolicy = ToolConsentPolicy.normalize({ publish_content: "always" });
    const disallowPolicy = ToolConsentPolicy.normalize({ trash_content: "disallow" });

    expect(
      ToolConsentPolicy.contextForTool("wp_publish_content", { id: 1, content_type: "post" }, alwaysPolicy).tier
    ).toBe("always");
    expect(
      ToolConsentPolicy.contextForTool("wp_trash_content", { id: 1, content_type: "post" }, disallowPolicy).tier
    ).toBe("disallow");
  });

  it("fails safe to ask for unknown destructive-looking tools", () => {
    const context = ToolConsentPolicy.contextForTool(
      "wp_vendor_bulk_delete_products",
      { product_ids: [1, 2, 3] },
      ToolConsentPolicy.defaults()
    );

    expect(context.action).toBe("vendor_bulk_delete_products");
    expect(context.tier).toBe("ask");
  });

  it("allows unmapped low-risk tools by default", () => {
    const context = ToolConsentPolicy.contextForTool(
      "wp_list_content_types",
      {},
      ToolConsentPolicy.defaults()
    );

    expect(context.tier).toBe("always");
  });
});
