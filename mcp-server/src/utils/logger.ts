const SENSITIVE_KEYS = new Set([
  "authorization",
  "wpAppPassword",
  "password",
  "token",
  "secret",
  "wp_app_password",
]);

function redact(value: unknown): unknown {
  if (!value || typeof value !== "object") {
    return value;
  }

  if (Array.isArray(value)) {
    return value.map(redact);
  }

  const redacted: Record<string, unknown> = {};

  for (const [key, nested] of Object.entries(value as Record<string, unknown>)) {
    if (SENSITIVE_KEYS.has(key)) {
      redacted[key] = "[REDACTED]";
      continue;
    }

    redacted[key] = redact(nested);
  }

  return redacted;
}

function write(level: "info" | "warn" | "error", message: string, context?: unknown): void {
  const payload = {
    level,
    message,
    timestamp: new Date().toISOString(),
    context: redact(context),
  };

  // Keep logs structured for easy ingestion.
  console.error(JSON.stringify(payload));
}

export const logger = {
  info: (message: string, context?: unknown) => write("info", message, context),
  warn: (message: string, context?: unknown) => write("warn", message, context),
  error: (message: string, context?: unknown) => write("error", message, context),
};
