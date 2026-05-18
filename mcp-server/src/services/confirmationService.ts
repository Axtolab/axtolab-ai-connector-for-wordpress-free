import { randomUUID } from "node:crypto";
import type { ConfirmationPayload } from "../types/contracts.js";
import { ToolError } from "../utils/errors.js";

interface StoredConfirmation {
  payload: ConfirmationPayload;
  expiresAtEpochMs: number;
}

export interface IssuedConfirmation {
  token: string;
  payload: ConfirmationPayload;
}

export class ConfirmationService {
  private readonly store = new Map<string, StoredConfirmation>();

  public constructor(private readonly ttlSeconds: number) {}

  public issue(action: string, key: string, input: unknown): IssuedConfirmation {
    const token = randomUUID();
    const issuedAt = new Date();
    const expiresAt = new Date(issuedAt.getTime() + this.ttlSeconds * 1000);

    const payload: ConfirmationPayload = {
      action,
      key,
      input,
      issued_at: issuedAt.toISOString(),
      expires_at: expiresAt.toISOString(),
    };

    this.store.set(token, {
      payload,
      expiresAtEpochMs: expiresAt.getTime(),
    });

    return { token, payload };
  }

  public consume(token: string, expectedAction: string, expectedKey: string): ConfirmationPayload {
    const stored = this.store.get(token);

    if (!stored) {
      throw new ToolError("INVALID_CONFIRMATION_TOKEN", "Confirmation token is invalid or already used");
    }

    this.store.delete(token);

    if (Date.now() > stored.expiresAtEpochMs) {
      throw new ToolError("EXPIRED_CONFIRMATION_TOKEN", "Confirmation token has expired");
    }

    if (stored.payload.action !== expectedAction || stored.payload.key !== expectedKey) {
      throw new ToolError("MISMATCH_CONFIRMATION_TOKEN", "Confirmation token does not match action payload");
    }

    return stored.payload;
  }
}
