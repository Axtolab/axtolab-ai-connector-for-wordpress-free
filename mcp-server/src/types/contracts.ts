export type ContentType = "post" | "page" | "featured_item";

export interface ApiEnvelope<T = unknown> {
  success: boolean;
  data?: T;
  error?: ApiError;
  audit_id?: string;
}

export interface ApiError {
  code: string;
  message: string;
  http_status?: number;
  details?: unknown;
  retryable?: boolean;
}

export interface ContentRecord {
  id: number;
  content_type: string;
  status: string;
  title?: string;
  slug?: string;
  excerpt?: string;
  content?: string;
  author?: number;
  date?: string;
  modified?: string;
  featured_media?: number;
  terms?: Record<string, number[]>;
  yoast_meta?: Record<string, unknown>;
  admin_edit_url?: string;
  preview_url?: string;
  public_url?: string;
}

export interface MediaRecord {
  id: number;
  title: string;
  source_url: string;
  thumbnail_url: string;
  mime_type: string;
  width: number;
  height: number;
  alt_text: string;
  caption: string;
  description: string;
  date: string;
}

export interface RevisionRecord {
  id: number;
  parent: number;
  author?: number;
  date?: string;
  modified?: string;
  title?: string;
}

export interface PreviewLinkRecord {
  post_id: number;
  wp_preview_url: string;
  signed_preview_url: string;
  expires_at: string;
}

export interface AuthorRecord {
  id: number;
  name: string;
  slug?: string;
  email?: string;
}

export interface TermRecord {
  id: number;
  taxonomy: string;
  name: string;
  slug: string;
  parent?: number;
}

export interface YoastAnalysisRecord {
  post_id: number;
  readability?: unknown;
  seo?: unknown;
  head_preview?: unknown;
}

export interface ConfirmationPayload {
  action: string;
  key: string;
  input: unknown;
  issued_at: string;
  expires_at: string;
}
