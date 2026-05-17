import { ToolError } from "../utils/errors.js";

interface TokenBucket {
  tokens: number;
  lastRefillEpochMs: number;
}

/**
 * Simple token-bucket rate limiter.
 * Limits the number of outgoing requests per time window.
 */
export class RateLimiter {
  private readonly bucket: TokenBucket;

  /**
   * @param maxTokens Maximum burst size (requests)
   * @param refillRatePerSecond Tokens added per second
   */
  public constructor(
    private readonly maxTokens: number = 30,
    private readonly refillRatePerSecond: number = 1
  ) {
    this.bucket = {
      tokens: maxTokens,
      lastRefillEpochMs: Date.now(),
    };
  }

  /**
   * Consume one token. Throws if rate limit exceeded.
   */
  public consume(): void {
    this.refill();

    if (this.bucket.tokens < 1) {
      throw new ToolError(
        "RATE_LIMIT_EXCEEDED",
        "Too many requests. Please wait before retrying.",
        { retryable: true }
      );
    }

    this.bucket.tokens -= 1;
  }

  /**
   * Check remaining tokens without consuming.
   */
  public remaining(): number {
    this.refill();
    return Math.floor(this.bucket.tokens);
  }

  private refill(): void {
    const now = Date.now();
    const elapsedSeconds = (now - this.bucket.lastRefillEpochMs) / 1000;
    const newTokens = elapsedSeconds * this.refillRatePerSecond;

    this.bucket.tokens = Math.min(this.maxTokens, this.bucket.tokens + newTokens);
    this.bucket.lastRefillEpochMs = now;
  }
}
