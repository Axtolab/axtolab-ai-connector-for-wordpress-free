import { describe, expect, it } from "vitest";
import { RateLimiter } from "../src/services/rateLimiter.js";

describe("RateLimiter", () => {
  it("allows requests within burst limit", () => {
    const limiter = new RateLimiter(5, 1);
    for (let i = 0; i < 5; i++) {
      expect(() => limiter.consume()).not.toThrow();
    }
  });

  it("rejects requests exceeding burst limit", () => {
    const limiter = new RateLimiter(3, 1);
    limiter.consume();
    limiter.consume();
    limiter.consume();
    expect(() => limiter.consume()).toThrowError(/too many requests/i);
  });

  it("refills tokens over time", async () => {
    const limiter = new RateLimiter(2, 100); // 100 tokens/sec refill
    limiter.consume();
    limiter.consume();
    expect(() => limiter.consume()).toThrowError(/too many requests/i);

    // Wait 50ms -> should refill ~5 tokens
    await new Promise((resolve) => setTimeout(resolve, 50));
    expect(() => limiter.consume()).not.toThrow();
  });

  it("reports remaining tokens", () => {
    const limiter = new RateLimiter(10, 1);
    expect(limiter.remaining()).toBe(10);
    limiter.consume();
    expect(limiter.remaining()).toBe(9);
  });

  it("does not exceed max tokens after long idle", async () => {
    const limiter = new RateLimiter(5, 100);
    await new Promise((resolve) => setTimeout(resolve, 100));
    expect(limiter.remaining()).toBe(5); // capped at max
  });
});
