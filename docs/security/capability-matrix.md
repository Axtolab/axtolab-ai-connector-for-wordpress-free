# Capability Matrix (Authoring Parity)

Last updated: 2026-02-18

This matrix defines what the MCP server must support for editorial parity, and the WordPress capability checks it must respect.

## 1) Principles
- Actions are allowed only if the authenticated WordPress user has required capabilities.
- Capability checks are content-type specific for CPTs.
- Route access is allowlisted by approved content types.
- Destructive actions require explicit confirmation in MCP flow.

## 2) Content types in scope
- Core: `post`, `page`
- Custom: approved CPT list from discovery (`/wp-json`) and config allowlist

## 3) Action to capability mapping

| Action | Typical WP capability check | Notes |
|---|---|---|
| Find/list content | `edit_posts` (or type-specific read/edit caps) | Scope to approved types only |
| Get content details (`context=edit`) | `edit_post` on target item | Needed for raw/edit fields |
| Create draft | `edit_posts` / type-specific `edit_posts` | CPTs may have custom caps |
| Update own draft | `edit_post` on target item | Include field-level allowlist |
| Update published content | `edit_published_posts` and/or `edit_post` | Depends on role + ownership |
| Publish | `publish_posts` / type-specific publish cap | Confirmation required |
| Schedule publish | publish capability + valid date | Confirmation required |
| Move to trash | `delete_post` on target item | Confirmation required |
| Restore from trash | `delete_post` or type-specific equivalent | Confirmation required |
| Permanent delete | `delete_post` on target item | Disabled by default recommended |
| Upload media | `upload_files` | Enforce file/type limits |
| List/search media library | `upload_files` or media read/edit capability | Needed for reuse instead of re-upload |
| Update media meta (alt/caption) | `edit_post` on attachment | Attachment is a post type |
| Set featured image | edit target post + valid media visibility | Requires media/post access |
| Insert inline image into content | `edit_post` on target + media access | Must preserve valid markup/block structure |
| Replace/remove inline image in content | `edit_post` on target + media access | Prefer structured patching over full overwrite |
| Assign categories/tags | term assign capability for taxonomy | Validate taxonomy allowlist |
| Create category/tag | `manage_categories` (or taxonomy-specific manage caps) | Optional, can be disabled |
| Set author on content | `edit_others_posts` and author validity | Must map to existing user |
| Read revisions | `edit_post` on target item | |
| Restore revision | `edit_post` + revision access | Confirmation required |
| Read Yoast SEO analysis for content | `edit_post` on target item | Includes readability/SEO score fetch where available |
| Update Yoast SEO metadata on content | `edit_post` on target item | e.g. SEO title, meta description, canonical, social metadata (field allowlist required) |
| Trigger Yoast indexing operations | elevated Yoast/admin capability | Treat as sensitive; confirmation required |
| Update Yoast global settings | admin-level capability | Disabled by default recommended |

## 4) Required capability verification tasks
1. Verify capabilities on staging for the exact integration user role.
2. Verify CPT capability maps via post type registration (`capability_type`, `map_meta_cap`, custom caps).
3. Verify taxonomy caps for custom taxonomies.
4. Confirm which operations should be disabled even if role technically permits them.

## 5) Guardrail policy
- Always enforce endpoint and field allowlists before calling WordPress.
- Reject content types outside approved list.
- Reject taxonomy operations outside approved taxonomy list.
- Reject Yoast operations outside approved Yoast route allowlist.
- Apply confirmation tokens for publish/trash/delete/restore.
- Log action, actor, content type, item id, and outcome (without leaking secrets).
- Enforce media policy:
  - allowed mime types
  - file size ceiling
  - required or auto-generated alt text
  - allowed source domains for URL-based imports (if enabled)

## 6) Pending configuration inputs
- Approved CPTs for first release
- Approved taxonomies for first release
- Whether term creation is enabled
- Whether permanent delete is enabled
- Inline media insertion mode (HTML vs block-aware vs dual)
- Media policy defaults (mime types, max size, alt-text requirement)
- Approved Yoast operation tiers for first release
