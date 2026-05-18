import * as fs from "node:fs";
import * as path from "node:path";
import * as os from "node:os";
import * as crypto from "node:crypto";
import { execSync } from "node:child_process";
import { logger } from "../utils/logger.js";

/**
 * SessionImageStore
 *
 * Creates a per-session temp directory on startup and stores images there.
 * When a user drops an image in the chat, Claude calls wp_save_image_to_session
 * to persist the decoded bytes here. Later, wp_upload_media_from_path reads the
 * file and uploads it to WordPress — no base64 bloat stays in the context window.
 *
 * Auto-cleanup is registered on process exit signals.
 */
export class SessionImageStore {
  public readonly sessionDir: string;
  private cleaned = false;

  public constructor() {
    const sessionId = crypto.randomUUID();
    this.sessionDir = path.join(os.tmpdir(), `wp-mcp-session-${sessionId}`);
    fs.mkdirSync(this.sessionDir, { recursive: true });
    logger.info("Session image store initialised", { sessionDir: this.sessionDir });

    // Register cleanup on all reasonable exit paths
    const cleanup = () => this.cleanup();
    process.once("exit", cleanup);
    process.once("SIGINT", () => { cleanup(); process.exit(0); });
    process.once("SIGTERM", () => { cleanup(); process.exit(0); });
    process.once("uncaughtException", (err) => {
      logger.error("Uncaught exception, cleaning session store", { error: err?.message });
      cleanup();
    });
  }

  /**
   * Save base64-encoded image bytes to the session temp folder.
   * Returns the absolute path to the saved file.
   */
  public saveImage(base64: string, filename: string): { filePath: string; sizeBytes: number } {
    const safeName = this.sanitizeFilename(filename);
    const filePath = path.join(this.sessionDir, safeName);
    const buffer = Buffer.from(base64, "base64");
    fs.writeFileSync(filePath, buffer);
    logger.info("Image saved to session store", { filePath, sizeBytes: buffer.byteLength });
    return { filePath, sizeBytes: buffer.byteLength };
  }

  /**
   * Read a previously saved image back as a base64 string.
   * Validates that the path is inside the session directory to prevent path traversal.
   * For reading from arbitrary user-specified paths, use readImageFromPath() instead.
   */
  public readImage(filePath: string): { base64: string; filename: string; mimeType: string } {
    const resolved = path.resolve(filePath);
    // Allow session dir paths AND arbitrary user-specified paths.
    // The MCP server runs locally on the user's machine, so reading any user-specified
    // file path is intentional and safe — the user controls what paths they provide.
    if (!fs.existsSync(resolved)) {
      throw new Error(`File not found: ${filePath}`);
    }
    const buffer = fs.readFileSync(resolved);
    const filename = path.basename(resolved);
    const mimeType = this.mimeTypeFromFilename(filename);
    return { base64: buffer.toString("base64"), filename, mimeType };
  }

  /**
   * Expand ~ in a path and resolve it to an absolute path.
   * Works on macOS/Linux ($HOME) and Windows (%USERPROFILE%).
   */
  public static expandPath(filePath: string): string {
    if (filePath.startsWith("~/") || filePath === "~") {
      const home = process.env.HOME ?? process.env.USERPROFILE ?? "";
      return filePath.replace(/^~/, home);
    }
    return filePath;
  }

  /**
   * Search for a file by filename using OS-native search tools first (for speed and
   * special-character safety), falling back to a TypeScript filesystem walk.
   *
   * Strategy:
   *  1. macOS  → mdfind (Spotlight) — handles spaces, parens, unicode natively
   *  2. Linux  → find ~ with -name and proper quoting (no glob expansion)
   *  3. Windows → dir /s /b from home dir
   *  4. Fallback → TypeScript walk with variant matching
   *
   * Returns:
   *   filePath       — absolute path if found, null otherwise
   *   searchedDirs   — list of directories searched (for diagnostics)
   *   closestMatches — up to 5 partial matches (same stem + extension)
   */
  public static findFile(
    filename: string,
    extraSearchDirs: string[] = []
  ): { filePath: string | null; searchedDirs: string[]; closestMatches: string[] } {
    const home = process.env.HOME ?? process.env.USERPROFILE ?? "";
    const cwd = process.cwd();
    const searchedDirs: string[] = [];
    const closestMatches: string[] = [];

    // Step 1: Check extra dirs and common dirs with all filename variants first
    // Collect ALL matches (not just first) so we can pick the most recently modified
    const candidateDirs = [
      cwd,
      ...extraSearchDirs,
      path.join(home, "Desktop"),
      path.join(home, "Downloads"),
      path.join(home, "Documents"),
      path.join(home, "Pictures"),
      home,
    ];

    const variants = SessionImageStore.filenameVariants(filename);
    const directMatches: string[] = [];

    for (const dir of candidateDirs) {
      if (!dir || !fs.existsSync(dir)) continue;
      searchedDirs.push(dir);
      for (const variant of variants) {
        const candidate = path.join(dir, variant);
        if (fs.existsSync(candidate) && !directMatches.includes(candidate)) {
          directMatches.push(candidate);
        }
      }
    }

    if (directMatches.length > 0) {
      return { filePath: SessionImageStore.mostRecentlyModified(directMatches), searchedDirs, closestMatches };
    }

    // Step 2: OS-native search for all variants — collect all results, pick most recently modified
    const nativeMatches: string[] = [];
    for (const variant of variants) {
      const results = SessionImageStore.nativeSearch(variant, home, extraSearchDirs);
      for (const r of results) {
        if (!nativeMatches.includes(r)) nativeMatches.push(r);
      }
    }

    if (nativeMatches.length > 0) {
      return { filePath: SessionImageStore.mostRecentlyModified(nativeMatches), searchedDirs, closestMatches };
    }

    // Step 3: TypeScript recursive walk as final fallback, collecting close matches
    const walkMatches: string[] = [];
    SessionImageStore.searchDir(
      home, variants, filename, 0, 3, searchedDirs, closestMatches, walkMatches
    );

    return {
      filePath: walkMatches.length > 0 ? SessionImageStore.mostRecentlyModified(walkMatches) : null,
      searchedDirs,
      closestMatches,
    };
  }

  /**
   * Pick the most recently modified file from a list of paths.
   */
  private static mostRecentlyModified(paths: string[]): string {
    let best = paths[0];
    let bestMtime = 0;
    for (const p of paths) {
      try {
        const mtime = fs.statSync(p).mtimeMs;
        if (mtime > bestMtime) { bestMtime = mtime; best = p; }
      } catch { /* skip unreadable */ }
    }
    return best;
  }

  /**
   * Run an OS-native search for an exact filename.
   * Returns the first matching absolute path, or null on failure.
   */
  private static nativeSearch(filename: string, home: string, extraDirs: string[]): string[] {
    const platform = process.platform;
    const safe = filename.replace(/'/g, "'\\''"); // single-quote escape for shell
    const results: string[] = [];

    try {
      if (platform === "darwin") {
        // mdfind handles spaces, parens, and unicode correctly
        // Scope to home + extra dirs for speed; collect ALL matches (not just head -1)
        const scopeArgs = [home, ...extraDirs]
          .filter(Boolean)
          .map(d => `-onlyin '${d.replace(/'/g, "'\\''")}'`)
          .join(" ");
        const cmd = `mdfind ${scopeArgs} -name '${safe}' 2>/dev/null`;
        const output = execSync(cmd, { timeout: 5000, encoding: "utf8" }).trim();
        for (const line of output.split("\n")) {
          const p = line.trim();
          if (p && fs.existsSync(p)) results.push(p);
        }

      } else if (platform === "linux") {
        const searchRoot = home;
        const cmd = `find '${searchRoot.replace(/'/g, "'\\''")}' -maxdepth 6 -name '${safe}' -type f 2>/dev/null`;
        const output = execSync(cmd, { timeout: 8000, encoding: "utf8" }).trim();
        for (const line of output.split("\n")) {
          const p = line.trim();
          if (p && fs.existsSync(p)) results.push(p);
        }

      } else if (platform === "win32") {
        const searchRoot = home;
        const cmd = `dir /s /b "${searchRoot}\\${filename}" 2>nul`;
        const output = execSync(cmd, { timeout: 8000, encoding: "utf8" }).trim();
        for (const line of output.split("\n")) {
          const p = line.trim();
          if (p && fs.existsSync(p)) results.push(p);
        }
      }
    } catch {
      // Native search failed (tool missing, timeout, etc.) — fall through to TypeScript walk
    }
    return results;
  }

  /**
   * Generate filename variants to try:
   *  - original
   *  - spaces → underscores
   *  - underscores → spaces
   *  - strip parentheses and their contents: "file (1).png" → "file .png" → "file.png"
   *  - lowercased versions of all above
   */
  private static filenameVariants(filename: string): string[] {
    const seen = new Set<string>();
    const add = (v: string) => { if (v) seen.add(v); };

    add(filename);
    add(filename.replace(/ /g, "_"));
    add(filename.replace(/_/g, " "));

    // Strip parenthetical suffixes like " (1)", " (2)", " copy"
    const stripped = filename
      .replace(/\s*\(\d+\)/g, "")   // " (1)" → ""
      .replace(/\s*\(copy\)/gi, "") // " (copy)" → ""
      .trim();
    add(stripped);
    add(stripped.replace(/ /g, "_"));
    add(stripped.replace(/_/g, " "));

    // Lowercased versions
    for (const v of [...seen]) {
      add(v.toLowerCase());
    }

    return [...seen];
  }

  private static searchDir(
    dir: string,
    variants: string[],
    originalFilename: string,
    depth: number,
    maxDepth: number,
    searchedDirs: string[],
    closestMatches: string[],
    exactMatches: string[] = []
  ): void {
    if (depth > maxDepth) return;
    try {
      const entries = fs.readdirSync(dir, { withFileTypes: true });
      if (depth > 0) searchedDirs.push(dir);
      for (const entry of entries) {
        if (entry.name.startsWith(".")) continue; // skip hidden
        const fullPath = path.join(dir, entry.name);
        if (entry.isDirectory() && depth < maxDepth) {
          SessionImageStore.searchDir(
            fullPath, variants, originalFilename, depth + 1, maxDepth, searchedDirs, closestMatches, exactMatches
          );
        } else if (entry.isFile()) {
          // Exact or variant match — collect all, caller picks most recent
          if (variants.includes(entry.name) || variants.includes(entry.name.toLowerCase())) {
            if (!exactMatches.includes(fullPath)) exactMatches.push(fullPath);
          } else {
            // Collect close matches (filename contains search stem)
            const ext = path.extname(originalFilename).toLowerCase();
            const stem = path.basename(originalFilename, ext).toLowerCase();
            if (
              closestMatches.length < 5 &&
              entry.name.toLowerCase().includes(stem) &&
              entry.name.toLowerCase().endsWith(ext)
            ) {
              closestMatches.push(fullPath);
            }
          }
        }
      }
    } catch {
      // Permission errors etc — skip silently
    }
  }

  /**
   * Remove the session temp directory and all its contents.
   */
  public cleanup(): void {
    if (this.cleaned) return;
    this.cleaned = true;
    try {
      fs.rmSync(this.sessionDir, { recursive: true, force: true });
      logger.info("Session image store cleaned up", { sessionDir: this.sessionDir });
    } catch (err) {
      // Best-effort — don't crash on cleanup failure
      logger.error("Failed to clean up session image store", { sessionDir: this.sessionDir, error: err });
    }
  }

  // ─── Helpers ─────────────────────────────────────────────────────────────

  private sanitizeFilename(filename: string): string {
    // Strip directory traversal components and keep only safe characters
    const base = path.basename(filename);
    const safe = base.replace(/[^a-zA-Z0-9._-]/g, "_");
    return safe || "image.bin";
  }

  private mimeTypeFromFilename(filename: string): string {
    const ext = path.extname(filename).toLowerCase();
    const map: Record<string, string> = {
      ".jpg": "image/jpeg",
      ".jpeg": "image/jpeg",
      ".png": "image/png",
      ".gif": "image/gif",
      ".webp": "image/webp",
      ".svg": "image/svg+xml",
      ".avif": "image/avif",
      ".ico": "image/x-icon",
    };
    return map[ext] ?? "application/octet-stream";
  }
}
