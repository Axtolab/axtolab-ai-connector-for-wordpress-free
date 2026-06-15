import { describe, expect, it } from "vitest";
import { PluginApiClient } from "../src/client/pluginApiClient.js";
import type { Config } from "../src/config.js";
import { ConfirmationService } from "../src/services/confirmationService.js";
import { PolicyService } from "../src/services/policyService.js";
import { RateLimiter } from "../src/services/rateLimiter.js";
import { SessionImageStore } from "../src/services/sessionImageStore.js";
import { SiteManager, type SiteServices } from "../src/services/siteManager.js";
import { ToolConsentPolicy, type ToolConsentTier } from "../src/services/toolConsentPolicy.js";
import { registerTools } from "../src/tools/registerTools.js";

type ToolHandler = (input: Record<string, unknown>) => Promise<{ content: Array<{ text: string }> }>;

function makeConfig(): Config {
  return {
    wpPluginBaseUrl: "https://example.com/wp-json/axtolab-ai-connector/v1",
    username: "test",
    appPassword: "pass",
    allowedContentTypes: [],
    allowedTaxonomies: [],
    allowedAuthors: [],
    allowedMediaTypes: [],
    allowedYoastPaths: [],
    rateLimitTokens: 60,
    rateLimitRefillRate: 1,
  };
}

function makeFakeServer() {
  const handlers = new Map<string, ToolHandler>();
  const states = new Map<string, { enabled: boolean }>();

  return {
    handlers,
    server: {
      tool(name: string, ...args: unknown[]) {
        const handler = args[args.length - 1];
        if (typeof handler === "function") {
          handlers.set(name, handler as ToolHandler);
        }

        const state = { enabled: true };
        states.set(name, state);
        return {
          enable() {
            state.enabled = true;
          },
          disable() {
            state.enabled = false;
          },
        };
      },
    },
  };
}

function makeSiteServices(options: {
  publishTier: ToolConsentTier;
  allowedTools: string[] | null;
  publishContent: () => Promise<Record<string, unknown>>;
}): SiteServices {
  const config = makeConfig();
  let services: SiteServices;
  const client = Object.assign(new PluginApiClient(config), {
    publishContent: options.publishContent,
    getConnectionCapabilities: async () => ({
      connection_id: "test-connection",
      capabilities: [],
      allowed_tools: services.allowedTools,
      allowed_author_ids: services.allowedAuthorIds,
      tool_consent_policy: services.toolConsentPolicy,
    }),
  });

  services = {
    config,
    client,
    policy: new PolicyService(config),
    toolConsentPolicy: ToolConsentPolicy.normalize({ publish_content: options.publishTier }),
    allowedTools: options.allowedTools,
    allowedAuthorIds: null,
    connectionCapabilityError: null,
  };

  return services;
}

async function callPublish(services: SiteServices, input: Record<string, unknown>) {
  const siteManager = new SiteManager(new Map([["example.com", services]]), "example.com");
  const { server, handlers } = makeFakeServer();

  registerTools({
    server,
    siteManager,
    confirmations: new ConfirmationService(300),
    rateLimiter: new RateLimiter(60, 1),
    sessionImageStore: new SessionImageStore(),
  });

  const handler = handlers.get("wp_publish_content");
  expect(handler).toBeDefined();

  const response = await handler!(input);
  return JSON.parse(response.content[0].text);
}

describe("connection capability and consent gates", () => {
  it("denies a disabled tool before issuing an ask-permission confirmation", async () => {
    let publishCalls = 0;
    const result = await callPublish(
      makeSiteServices({
        publishTier: "ask",
        allowedTools: ["wp_site_info"],
        publishContent: async () => {
          publishCalls += 1;
          return { published: true };
        },
      }),
      { id: 12, content_type: "post" }
    );

    expect(result.success).toBe(false);
    expect(result.error.code).toBe("capability_denied");
    expect(result.data?.requires_confirmation).toBeUndefined();
    expect(publishCalls).toBe(0);
  });

  it("issues a confirmation for ask-permission actions before execution", async () => {
    let publishCalls = 0;
    const services = makeSiteServices({
      publishTier: "ask",
      allowedTools: ["wp_publish_content"],
      publishContent: async () => {
        publishCalls += 1;
        return { published: true };
      },
    });
    const siteManager = new SiteManager(new Map([["example.com", services]]), "example.com");
    const { server, handlers } = makeFakeServer();

    registerTools({
      server,
      siteManager,
      confirmations: new ConfirmationService(300),
      rateLimiter: new RateLimiter(60, 1),
      sessionImageStore: new SessionImageStore(),
    });

    const handler = handlers.get("wp_publish_content");
    expect(handler).toBeDefined();

    const first = JSON.parse((await handler!({ id: 12, content_type: "post" })).content[0].text);
    expect(first.success).toBe(true);
    expect(first.data.requires_confirmation).toBe(true);
    expect(first.data.confirmation_payload.action).toBe("publish_content");
    expect(first.data.confirmation_payload.key).toBe("post:12:publish");
    expect(publishCalls).toBe(0);

    const second = JSON.parse(
      (
        await handler!({
          id: 12,
          content_type: "post",
          confirmation_token: first.data.confirmation_token,
        })
      ).content[0].text
    );
    expect(second.success).toBe(true);
    expect(second.data.published).toBe(true);
    expect(publishCalls).toBe(1);
  });

  it("runs always-allow actions without a confirmation token", async () => {
    let publishCalls = 0;
    const result = await callPublish(
      makeSiteServices({
        publishTier: "always",
        allowedTools: ["wp_publish_content"],
        publishContent: async () => {
          publishCalls += 1;
          return { published: true };
        },
      }),
      { id: 12, content_type: "post" }
    );

    expect(result.success).toBe(true);
    expect(result.data.published).toBe(true);
    expect(result.data.requires_confirmation).toBeUndefined();
    expect(publishCalls).toBe(1);
  });

  it("refreshes changed consent policy before the next tool call", async () => {
    let publishCalls = 0;
    const services = makeSiteServices({
      publishTier: "always",
      allowedTools: ["wp_publish_content"],
      publishContent: async () => {
        publishCalls += 1;
        return { published: true };
      },
    });
    const siteManager = new SiteManager(new Map([["example.com", services]]), "example.com");
    const { server, handlers } = makeFakeServer();

    registerTools({
      server,
      siteManager,
      confirmations: new ConfirmationService(300),
      rateLimiter: new RateLimiter(60, 1),
      sessionImageStore: new SessionImageStore(),
    });

    services.toolConsentPolicy = ToolConsentPolicy.normalize({ publish_content: "ask" });

    const handler = handlers.get("wp_publish_content");
    expect(handler).toBeDefined();

    const result = JSON.parse((await handler!({ id: 12, content_type: "post" })).content[0].text);

    expect(result.success).toBe(true);
    expect(result.data.requires_confirmation).toBe(true);
    expect(result.data.confirmation_payload.action).toBe("publish_content");
    expect(publishCalls).toBe(0);
  });

  it("blocks disallowed sensitive actions even when the tool family is enabled", async () => {
    let publishCalls = 0;
    const result = await callPublish(
      makeSiteServices({
        publishTier: "disallow",
        allowedTools: ["wp_publish_content"],
        publishContent: async () => {
          publishCalls += 1;
          return { published: true };
        },
      }),
      { id: 12, content_type: "post" }
    );

    expect(result.success).toBe(false);
    expect(result.error.code).toBe("tool_consent_disallowed");
    expect(publishCalls).toBe(0);
  });
});
