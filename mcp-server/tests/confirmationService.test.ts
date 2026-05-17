import { describe, expect, it } from "vitest";
import { ConfirmationService } from "../src/services/confirmationService.js";

describe("ConfirmationService", () => {
  it("issues and consumes matching token", () => {
    const service = new ConfirmationService(60);
    const issued = service.issue("publish_content", "post:1:publish", { id: 1 });

    const payload = service.consume(issued.token, "publish_content", "post:1:publish");
    expect(payload.input).toEqual({ id: 1 });
  });

  it("rejects reused token", () => {
    const service = new ConfirmationService(60);
    const issued = service.issue("publish_content", "post:1:publish", { id: 1 });

    service.consume(issued.token, "publish_content", "post:1:publish");
    expect(() => service.consume(issued.token, "publish_content", "post:1:publish")).toThrowError(
      /invalid/i
    );
  });

  it("rejects mismatched action", () => {
    const service = new ConfirmationService(60);
    const issued = service.issue("publish_content", "post:1:publish", { id: 1 });

    expect(() => service.consume(issued.token, "trash_content", "post:1:trash")).toThrowError(/does not match/);
  });

  it("rejects expired token", async () => {
    const service = new ConfirmationService(0.1); // 0.1 second TTL
    const issued = service.issue("publish_content", "post:1:publish", { id: 1 });

    // Wait for expiry
    await new Promise((resolve) => setTimeout(resolve, 200));
    expect(() => service.consume(issued.token, "publish_content", "post:1:publish")).toThrowError(
      /expired/i
    );
  });
});
