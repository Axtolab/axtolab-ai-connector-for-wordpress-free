import type { ApiError } from "../types/contracts.js";

export class ToolError extends Error {
  public readonly code: string;
  public readonly details?: unknown;
  public readonly httpStatus?: number;
  public readonly retryable: boolean;

  public constructor(
    code: string,
    message: string,
    options?: { details?: unknown; httpStatus?: number; retryable?: boolean }
  ) {
    super(message);
    this.name = "ToolError";
    this.code = code;
    this.details = options?.details;
    this.httpStatus = options?.httpStatus;
    this.retryable = options?.retryable ?? false;
  }
}

export function toToolError(error: unknown): ToolError {
  if (error instanceof ToolError) {
    return error;
  }

  if (error instanceof Error) {
    return new ToolError("UNEXPECTED_ERROR", error.message);
  }

  return new ToolError("UNEXPECTED_ERROR", "Unexpected unknown error", { details: error });
}

export function serializeError(error: unknown): ApiError {
  const normalized = toToolError(error);
  return {
    code: normalized.code,
    message: normalized.message,
    details: normalized.details,
    http_status: normalized.httpStatus,
    retryable: normalized.retryable,
  };
}
