import { afterEach, describe, expect, it } from "vitest";
import { loadConfig } from "../src/config.js";

describe("loadConfig", () => {
  const savedEnv: Record<string, string | undefined> = {};

  afterEach(() => {
    for (const key of Object.keys(savedEnv)) {
      if (savedEnv[key] === undefined) {
        delete process.env[key];
      } else {
        process.env[key] = savedEnv[key];
      }
    }
    Object.keys(savedEnv).forEach((k) => delete savedEnv[k]);
  });

  function setEnv(vars: Record<string, string | undefined>) {
    for (const [key, val] of Object.entries(vars)) {
      savedEnv[key] = process.env[key];
      if (val === undefined) {
        delete process.env[key];
      } else {
        process.env[key] = val;
      }
    }
  }

  it("loads required values from legacy env vars", async () => {
    setEnv({
      WP_MCP_SITE: undefined,
      WP_PLUGIN_BASE_URL: "https://example.com/wp-json/wp-mcp-gateway/v1",
      WP_USERNAME: "tester",
      WP_APP_PASSWORD: "app-pass",
    });

    const config = await loadConfig();

    expect(config.wpPluginBaseUrl).toBe("https://example.com/wp-json/wp-mcp-gateway/v1");
    expect(config.username).toBe("tester");
    expect(config.appPassword).toBe("app-pass");
    expect(config.httpTimeout).toBe(30_000);
  });

  it("throws for missing required variables", async () => {
    setEnv({
      WP_MCP_SITE: undefined,
      WP_PLUGIN_BASE_URL: undefined,
      WP_USERNAME: undefined,
      WP_APP_PASSWORD: undefined,
    });

    await expect(loadConfig()).rejects.toThrow(/WP_PLUGIN_BASE_URL/);
  });
});
