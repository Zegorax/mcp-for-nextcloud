# MCP for Cloud — Technical documentation

Português (Brasil): [README.pt-BR.md](README.pt-BR.md)

Version for **Nextcloud 32 to 35** (`info.xml` declares 32–35; the API contract has fixtures for 31 to 35, and 31 is still checked without being declared). The endpoint serves MCP 2026-07-28 clients, stateless, through `server/discover` and per-request `_meta`, and also earlier clients, which use `initialize` with 2025-06-18. It exposes MCP `2025-06-18` over sessionless Streamable HTTP without SSE, on the app's own route:

- `https://<instance>/apps/mcp/` with clean URLs;
- `https://<instance>/index.php/apps/mcp/` without clean URLs.

The exact URL of the instance appears on the administration and personal pages.

## Contacts and Tasks (0.8.0)

| Module | Tools | Dependency |
|---|---|---|
| Contacts | `contacts_list_addressbooks`, `contacts_search_contacts`, `contacts_read_contact`, `contacts_create_contact`, `contacts_edit_contact`, `contacts_delete_contact` | Contacts app enabled; core CardDAV |
| People | `users_search` | account search — and, with `include_groups: true`, group search, returning `type` `user` or `group` — through Nextcloud sharing (honors the admin restrictions); no optional app |
| Logs | `logs_list`, `logs_analyze` | read-only access to `nextcloud.log` through the core's `ILogFactory`/`IFileBased::getEntries`; requires a file-based `log_type` (the default); see [Server log](#server-log-102) |
| Tasks | `tasks_list_calendars`, `tasks_list_tasks`, `tasks_read_task`, `tasks_create_task`, `tasks_edit_task`, `tasks_complete_task`, `tasks_delete_task` | core DAV; does not require the Tasks or Calendar app |

The Contacts catalogs are own/shared, explicitly filtering any system principal. Reads use lazy adapters of the native backend, after the access filter; writes go through the core's Sabre (a separate CardDAV dispatcher and the existing `EmbeddedDavDispatcher` for VTODO). The session must match the actor and the principal is fixed at execution. `CalendarAccess` keeps VEVENT as the default and receives VTODO only in the Tasks module.

`GrantPolicy`/`GrantMatrix` include `contacts` and `tasks` with read/create/edit/delete, with writes off by default. Completing a task uses edit. `PreviewsWrites` produces the plan with no side effects; `WriteGate` requires confirm:true; shares require confirm_shared; a supplied ETag is checked and If-Match uses the re-read one. Editing a vCard preserves unknown fields, parameters, groups and the version; the plan includes every before/after property and the full vCard.

**Deleting a contact is PERMANENT in Nextcloud 33, with no trash bin.** Only after confirmation, `ContactBackup` saves the complete original vCard in `/MCP backups/Contacts/<catalog>/<name-or-uid>.<YYYYmmdd-HHMMSS>-<unique-suffix>.vcf`, in the account's time zone and in the actor's folder. It uses an application mutex, a random suffix and a collision check so it never selects existing files, and verifies the copy by re-reading the bytes. A failure prevents the CardDAV DELETE; the result reports backupPath. Re-import through the Contacts interface to recover. No backup is created in the preview.

Tasks use VTODO, include undated tasks, hide PRIVATE/CONFIDENTIAL ones of another owner and exclude objects in the trash bin. Reading recurrence is allowed; writes on series or tasks with attendees are refused. Fields that did not change stay in the iCalendar; completing writes STATUS/PERCENT-COMPLETE/COMPLETED, and lowering progress reopens the task. Deleting uses the CalDAV trash bin, refuses zero retention and checks deleted-at after the DELETE. Listings use limit/offset/nextOffset; completed tasks require include_completed. Categories (`CATEGORIES`) are free text: reading and listing return `categories`, merged from every `CATEGORIES` line (escaped commas respected), trimmed and deduplicated ignoring Unicode case, keeping the first spelling; create and edit accept `categories` (up to 50 items of 1–255 characters after trimming, no control characters, validated before any write): omitting it leaves every line untouched, `[]` removes them and a list replaces them with one multi-value line. `tasks_list_tasks` filters by `category` (exact, case-folded) before `offset`/`limit`, together with `include_completed`; the plan shows each name quoted, before and after. Categories of tasks hidden by CLASS are never read, filtered or shown.

There is no migration, approval_id, approval record, occ requirement, extra service or auxiliary database. Descriptions/ToolGuideNotes stay in English; titles and messages use Translator with pt_BR/es parity. Tests cover plans, backup, ACL, grants, ETag, preservation and real Sabre dispatch; they do not prove the internal plugins on a real Nextcloud installation.

## Tools

Each tool only appears in `tools/list` and can only be called when the user has the grant for the operation and the required app is enabled for them. Grant and app are checked again on every call. Everything runs as the authenticated user, through their own Nextcloud folder, and the Nextcloud permissions and shares keep applying.

### Tool guide (`mcp_guide`)

`mcp_guide` (title **Tool guide**) describes the tools to the model itself, so it does not have to guess what each one does:

- **no argument** — one line per module with the tool titles, only for the modules available **to that user** (the same grant and app filter as `tools/list`: a disabled module or one without a grant does not appear);
- **with `module`** (`files`, `notes`, `calendar`, `contacts`, `tasks`, `deck`, `talk`, `people`, `logs`) — each tool of the module: description, whether it reads or writes, parameters with type, whether required, description, `enum` and limits, and the confirmations it requires;
- **with `tool`** — a single tool, with the module it belongs to.

The text comes from the real definitions (`ToolModule::definitions()`, `inputSchema`, `annotations` and `ToolPresentation`), so changing a tool's schema changes the guide: there is no second copy of the documentation to keep up to date. What the schema does not carry — how to find things, with which paths and IDs, and where writes stop — goes in `ToolGuideNotes::guideNotes()`, a short note per module, written next to the module. The response carries the same content in markdown and in `structuredContent`, and the tool is read-only: it reads definitions, it never runs a tool.

A module or tool the user cannot see is refused with a message that lists what they can use. There is no approval stored on the server: the rule of confirming before writing lives in the `instructions` of `initialize` and applies to every tool that changes something.

| Tool | Grant | Notes |
| --- | --- | --- |
| `mcp_status` | — | Diagnostics; does not read user data. |
| `files_list` `{path="/"}` | files.read | Direct children of the folder, as `{name, path, isDir, size, mtime, contentType}`. |
| `files_search` `{query, limit=25, mode="auto"}` | files.read | Content search when `fulltextsearch` and `files_fulltextsearch` are active and indexed (with highlighted snippets); otherwise, or on error, name search (`%`, `_` and `\` are literal), 1 to 100 results. The `mode` parameter (`auto` or `name`) forces name search. |
| `files_read` `{path}` | files.read | Plain text, PDF, DOCX and ODT. A file above 20 MiB is refused before reading, and the text is truncated at 100 000 characters. A PDF or image without a text layer returns `text_layer: false` with a notice (not an error); if the optional **Workflow OCR** app (`workflow_ocr`, requires `ocrmypdf` on the server) is active, it writes the text into the PDF in the background and a new read already returns it. The admin page shows the Workflow OCR status. |
| `files_tree` `{path="/", depth=5, limit=2000}` | files.read | Folder tree with the owner of each item, to plan a reorganization without one call per folder. Honors depth (up to 5) and count (up to 2000) and warns when it truncates. |
| `files_image_view` `{path, max_size=1568}` | files.read | Downscaled preview in base64 (JPEG/PNG/WebP/GIF/TIFF/PDF) under a byte limit (default 1 MB), with a fallback to the original for safe formats. Includes brief metadata. |
| `files_images_view` `{paths?, folder?, limit=6, max_size=1568}` | files.read | Preview of several images (up to 6) under a total byte budget (default 4 MB); dropped items are reported in the summary. |
| `files_image_search` `{query?, folder?, modified_after?, modified_before?, tag?, limit=25}` | files.read | Search exclusively for image files (`image/%`), with filters for name, folder, ISO dates and system tags (includes the automatic Recognize tags). |
| `files_edit` `{path, content, etag?}` | files.edit | Only an existing text file, at most 10 MiB. Before writing, requires `files_versions` to be active and copies the original to `/MCP backups/<path>/<name>.<YYYYmmdd-HHMMSS>.bak`; if any step fails, nothing is written. With a mismatching `etag`, nothing is written. It does not create, move or delete. Outside the personal folder, requires `confirm_shared: true` after asking the user. |
| `files_replace` `{path, old, new, etag?}` | files.edit | Replaces a snippet that must appear exactly once and returns the diff. Same requirements as `files_edit`: versioning active, backup in `/MCP backups`, and a mismatching `etag` writes nothing. Outside the personal folder, requires `confirm_shared: true`. |
| `files_checkout` `{path}` | files.edit | Accepts any file type (DOCX, XLSX, PDF, images) up to the upload limit; `files_edit`/`files_replace` remain text only. Returns two one-time links to edit with a local tool (curl) without passing the content through the model: the download is valid for 5 minutes and the upload for 15. Each link is the credential, bound to the user, the file and the current ETag; if the file changes before the upload, nothing is written. The upload creates the same backup as `files_edit`. Outside the personal folder, it only issues the links with `confirm_shared: true`. |
| `files_upload` `{path, size?}` | files.create | Creates a **new** file of any type (DOCX, XLSX, PDF, image) generated locally by the client, without passing the content through the model. The plan shows the name, the folder, the link validity and the upload limit (the same as `files_checkout`); a `size` above the limit is refused before the link is issued. The confirm returns `uploadUrl`, a one-time link valid for 15 minutes, bound to the user and the path, and the command `curl -sS -T /path/to/local/file -X PUT -H "Content-Type: application/octet-stream" "<uploadUrl>"`; the PUT response (201) carries `path`, `size`, `etag` and `fileId`. It never overwrites a file that has content: an existing name is refused in the plan, in the confirm and in the upload; if another client creates the name with content in between, or changes the file between creation and writing, the response is 409 and nothing is written (an empty file created at the same instant is taken over). An empty body creates an empty file, except when the plan declared `size` > 0 (400). A missing, spent or expired link is refused before the body goes to disk. A multipart body (400), above the limit (413), an already existing name (409), a removed folder (404), a folder that became shared (409) or no permission (403) do not spend the link. The folder must exist (`files_mkdir`); a hidden folder answers as nonexistent. Outside the personal folder, requires `confirm_shared: true`. There is no backup: there is no original. |
| `files_create` `{path, content}` | files.create | Creates a **new** text file with inline content (`.md`, `.txt`, `.csv`, `.json`, `.html`, `.xml`, `.yaml`, `.yml`), up to 1 MB in UTF-8. The plan shows name, folder, size and the quoted beginning of the text. Same rules as `files_upload`: it never overwrites a file that has content, names that Nextcloud refuses (`IFilenameValidator`) are an argument error without echoing the value, the folder must exist, `confirm_shared` outside the personal folder. For another type or a larger file, `files_upload`; to replace an existing file, `files_checkout`. |
| `files_mkdir` `{path}` | files.create | Creates a folder, including intermediate levels. Refuses an already existing destination and never deletes content. Outside the personal folder, requires `confirm_shared: true`. |
| `files_copy` `{from, to, etag?}` | files.create | Copies a file or folder to another path, creating a new node with a new id; it never overwrites the destination. The copy does not preserve id, versions or shares. Limit of 2000 items and 1 GiB, measured before copying. Outside the personal folder, source or destination require `confirm_shared: true`. |
| `files_move` `{from, to, etag?}` | files.move | Moves or renames within the same storage, without overwriting the destination. Returns the id before and after, and the version and share counts when the apps are active. Across storages Nextcloud treats it as a copy and the id changes. Outside the personal folder, source or destination require `confirm_shared: true`. |
| `files_move_batch` `{moves, mkdirs?, confirm?}` | files.move | Up to 200 moves, with folders created first in the given order. Without `confirm: true` it writes nothing and returns the plan: what would go through, the conflicts, what Nextcloud denies and what belongs to someone else. With `confirm: true` it executes and returns the `batch_id` for `files_undo_batch`. An item that fails midway stops the batch, and what was already moved stays recorded for undo. Items of someone else require `confirm_shared: true`. |
| `files_undo_batch` `{batch_id, confirm: true}` | files.move | Undoes a `files_move_batch` in reverse order. Before changing anything it checks the whole batch: if any item is no longer what the batch put at the destination, if the source path is already taken or if permissions changed, nothing is undone and the response points to the item that blocked it. It only removes empty folders created by the batch itself; a folder with content is preserved and goes in `kept_dirs`. It is the only place where Files removes anything. |
| `files_versions_list` `{path, limit=50}` | files.read | File versions through the `files_versions` app, newest to oldest. |
| `files_version_read` `{path, version}` | files.read | Text of an earlier version, with the same limit and the same text extraction as `files_read`. |
| `files_version_restore` `{path, version, confirm: true}` | files.restore | Restores the content of a stored version, after backing up the current content in `/MCP backups`; versioning follows the Nextcloud policy. Requires `confirm: true` and refuses the backup folder itself, as editing already did. Outside the personal folder, it also requires `confirm_shared: true`. |
| `files_list_shares` `{path?, offset=0}` | files.read | Shares the user themselves created. With `path`, those of their file or folder (a node of another owner is refused; a hidden node is treated as nonexistent). Without `path`, all of them, 50 per page, with `offset` up to 1000. Each item carries `shareId`, `type` (`user`, `group`, `link` or `room`), `with` (id and display name), `permission` (`view`, `edit` or `custom`), `reshare`, `expires`, `hasPassword`, `url` (link only), `removable` and `path`. The password never leaves. A Talk attachment (`room`) appears with `removable: false` and is removed through Talk. Creating and removing shares require the `files.share` (people and groups) and `files.link` (public link) permissions, denied by default. |
| `files_share` `{path, with, permission="view", expires?, note?, password?, confirm: true, plan_state}` | files.share (person/group) or files.link (link) | Shares a file or folder of the user themselves with a person (`user:<uid>`) or a group (`group:<gid>`); find the id with `users_search` (`include_groups: true` for groups). Sharing again with the same recipient changes the existing share, and the plan shows before → after, including the reshare that a share made through the web loses. If nothing changes, the plan says "nothing to change" and the confirm writes nothing. `view` = view and download; `edit` = edit (in a folder, also add and delete); it never grants the right to pass it on. `expires` (YYYY-MM-DD, in the account's time zone, from tomorrow) honors the admin maximum; without it, a new share receives the admin default. The admin rules (sharing off, only with members of the groups, groups off) are explained before writing; core refusals become translated messages, and the log keeps only the class. Nextcloud notifies the recipient. Public link (`with: "link"`, permission `files.link`): one per file, `view` only, and the plan warns that anyone with the link can open it. It refuses when the admin turned links off and honors the maximum expiration and the link default. The password is generated by the server (`GenerateSecurePasswordEvent` event of `password_policy`; without it, 16 characters from `ISecureRandom` with letters, digits and symbols), validated by the policy before writing and created when `password: true` is requested or when the admin requires a password. It never appears in the plan, in the log or in `files_list_shares`: only in the result of the execution that generated it, a single time. Without `password: true`, the current password is kept and not shown. The plan carries `plan_state` (opaque fingerprint of the action, the share and the before/after fields, without password), which the confirm returns: if the share changed since the plan, nothing is written and the response is the new plan, with a notice to check again; a confirm without `plan_state` is an argument error. |
| `files_unshare` `{shareId}` or `{path, with}` + `confirm: true` and `plan_state` | files.share (person/group) or files.link (link), according to the type removed | Removes a share the user themselves created on a file or folder of theirs; the file is never touched. The share is named by the `shareId` from `files_list_shares` (for example `ocinternal:123`) or by `path` with `with` (`user:<uid>`, `group:<gid>` or `link`). The plan says who loses access ("Alex will no longer have access to X", "all members of group G will lose access", "the link of X will stop working"). A share of someone else, of a file of another owner, of a hidden node, of an unmanaged type or a nonexistent id all answer "not found", revealing nothing; only the user's own Talk attachment receives the message that it is removed through Talk. The grant of the type is checked after ownership, and everything is checked again at the confirm. As with `files_share`, the confirm returns the `plan_state` of the plan; if the share changed or was recreated since the plan, nothing is removed and the response is the new plan. |
| `notes_list` `{}` | notes.read | `.md`/`.txt` notes in the Notes app folder (`notesPath` preference, default `Notes`). |
| `notes_search` `{query, limit=20, category=""}` | notes.read | Search by title and Markdown content (up to 1 MiB), with a contextual snippet of the match. Optionally restricted to a category. |
| `notes_read` `{id}` | notes.read | Note with content; limit of 1 MiB. |
| `notes_create` `{title, content="", category=""}` | notes.create | Never overwrites; on collision, uses `Title (2)`. |
| `notes_edit` `{id, content?, title?, etag?}` | notes.edit | Content and/or title. |
| `notes_move` `{id, category, etag?}` | notes.move | Changes category (subfolder), without overwriting. |
| `notes_delete` `{id, etag?}` | notes.delete | Requires `confirm: true`, the trash bin (`files_trashbin`) active and the note's storage covered by it; on external storage without a trash bin, deletion is blocked. |

| `calendar_list_calendars` `{}` | calendar.read | Calendars visible to the user, own and shared. |
| `calendar_list_events` `{calendar?, from?, to?}` | calendar.read | Events in the range (default: next 7 days), with recurrence expanded. |

Calendar exposes reading and the five writes, with no terminal step. The writes start off in the admin matrix (Administration → MCP) and only appear for those who received the grant; every write also requires `confirm: true`, the Nextcloud ACL (and `confirm_shared` on someone else's calendar) and the optional ETag. Compatibility: the app declares `min-version` 32 and `max-version` 35 in `info.xml`; the internal part (the core's `EmbeddedCalDavServer`) is covered by the tests and by that range, and a new Nextcloud version requires a new version of the app. The Calendar app must be enabled for the user. Sabre and Symfony Console come from the Nextcloud core and stay out of the package's `vendor/`.

| Write tool | Grant | Behavior |
| --- | --- | --- |
| `calendar_create_event` | calendar.create | Simple event; `attendees` are internal UIDs resolved to the account's email. |
| `calendar_update_event` | calendar.edit | Updates text/dates and replaces attendees; `attendees: []` removes all. An attendee change on a series or by another organizer is refused. |
| `calendar_move_event` | calendar.move | MOVE within the same owner; attendees are not notified, as in the Calendar app. |
| `calendar_delete_event` | calendar.delete | Deletes the series into the trash bin; requires active retention. |
| `calendar_transfer_event` | calendar.transfer | MOVE to a calendar of another owner shared with write access; refuses attendees. |

Create, update and delete use `send_invitations: false` by default (`x-nc-scheduling: false`). `true` hands the invitation to Nextcloud's CalDAV scheduling, including existing guests and CANCEL. The plan and the result report `imipEnabled`, read from the native `dav/sendInvitations` setting: with it off the server does not send email (the internal iTIP still reaches the guest's inbox) and the assistant must tell the user so. The app never confirms email delivery. Writes on someone else's calendar still require `confirm_shared: true` after user confirmation.

**Confirmation before every write (no state on the server).** The five writes, called without `confirm: true`, only return a plan (`requiresConfirmation: true`): current and proposed values, attendees added/removed, destination, consequence for invitations/CANCEL/trash bin and the current `etag`. Nothing is written or scheduled. The assistant must show the plan, ask the user and only after an explicit yes repeat the call with `confirm: true` and the same arguments, resending the `etag` of the plan (optional; if it comes and differs, the write is refused and a new plan is needed). There is no table, token or approval record: the server does not prove the human yes, but permissions, ACL, grants and `confirm_shared` keep applying. The selftest uses the handlers directly and does not go through this flow.

### Deck: cards, boards and lists

The Deck writes use the grants `deck.create`, `deck.edit`, `deck.move` and `deck.delete`, all off by default, and `confirm_shared: true` on a board of another owner. The app always confirms with Deck whether the write was saved, even after a Deck error.

| Write tool | Grant | Behavior |
| --- | --- | --- |
| `deck_create_board` `{title, stacks?, confirm: true}` | deck.create | Creates a board of the user themselves with its lists and first cards (up to 20 lists and 100 cards), with an optional color. On a new board the assignees are the user themselves, because no one else has access. The plan shows the whole tree; if a step fails midway, nothing is undone and the result carries `created`, `failed` (with a safe reason) and `warnings`, so a retry does not duplicate anything. |
| `deck_create_stack` `{boardId, title, position?, confirm: true}` | deck.create | Adds a list to a board the user manages, at the end or at a position. |
| `deck_delete_stack` `{stackId, confirm: true}` | deck.delete | Deletes a list **only when it is empty**: any card, active or archived, refuses the call saying how many remain, and the count is redone at the confirm. It goes to the Deck trash bin. |
| `deck_delete_board` `{boardId, confirm: true}` | deck.delete | Same for the board, and only the owner can delete. If a card appears between the count and the deletion, the board comes back with Deck's own undo and the call is refused saying that nothing was deleted. |

`deck_create_card`, `deck_edit_card`, `deck_move_card`, `deck_delete_card` and the assignment of assignees use the same grants and the same confirmation; the assignees are board members.

After an error coming from Deck, every write re-reads the real state before answering: if the item was saved, the response is a success with the notice "Saved; Deck reported an error afterwards (notification or activity)"; if not, it is the usual error; and if the state cannot be read, the response is not an error and tells the assistant to read with `deck_list_cards`, `deck_read_card`, `deck_list_stacks` or `deck_list_boards`. An item found again only by similarity (same owner, same title, created a moment ago) comes as `confirmed: "probable"`, with a notice to check, and nothing else is written to it. Only the refusals the app itself makes before calling Deck (tool rules, session, conflicts, arguments) skip this re-read.

The "only when empty" deletion is not atomic: Deck does not look at the list's `deleted_at` when creating a card, so a card created by another request right after the second count may go to the trash bin together with the list or the board, from where it is recoverable. This is in the tool description and in the gateway.

### Optional Calendar diagnostics (`occ mcp:calendar-selftest`)

It is not a prerequisite: nothing depends on it for the tools to appear or work, and it neither writes nor hides anything. It is an optional, advanced diagnostic tool for those who administer the server and want to see, step by step, the real CalDAV responding. Run it at the Nextcloud root as the web server user. The organizer UID must have an enabled account, a valid email and Calendar enabled; the `dav/calendarRetentionObligation` retention must not be `0` (the cleanup would be permanent, so it fails before creating any object).

```sh
sudo -u www-data php occ mcp:calendar-selftest ORGANIZER_UID
```

Each run creates two calendars with URIs `mcp-selftest-...-a`/`-b`, touches only events created by it and moves those calendars to the trash bin at the end. Without options, the three optional proofs show `NOT TESTED`. To exercise internal invitations, transfer and ACL:

```sh
sudo -u www-data php occ mcp:calendar-selftest ORGANIZER_UID \
  --attendee-uid ATTENDEE_UID \
  --shared-calendar '/remote.php/dav/calendars/ORGANIZER_UID/SHARED_URI/' \
  --acl-probe-user NO_ACCESS_UID
```

This run has effects on both users (internal invitation, cleanup CANCEL, Activity on the shared calendar) and removes only items with the UID generated by the test. It is not proof of SMTP.

| Report step | Expected result |
| --- | --- |
| `preflight`, `calendars` | Valid account/app/retention; two MKCALENDAR HTTP 201 visible. |
| `create` | HTTP 201, UID and ETag re-read, sync-token increased. |
| `suppression-create`, `suppression-update` | PUT 201/204, ATTENDEE `@example.invalid` without SCHEDULE-STATUS; SEQUENCE went up. |
| `stale-tool`, `stale-dav` | Conflict in the handler and HTTP 412 in DAV; data and ETag intact. |
| `move`, `overwrite` | MOVE 201 with the UID only at the destination; Overwrite F returns 412 and preserves both objects. |
| `delete` | HTTP 204, UID out of the read and in the trash bin. |
| `invitations`, `transfer`, `acl` | `OK` if requested and proven; `NOT TESTED` without the options. |
| `cleanup`, `result` | Cleanup HTTP 204 confirmed, session restored and final result of the diagnostics. |

Any `FAIL` returns a non-zero code. If the cleanup fails, `cleanup-resource` indicates the path/UID to check on the server. The result does not change which tools exist.

Notes tools require the Notes app enabled for the user. Reading starts allowed; creating, editing, moving and deleting start denied until the administrator grants them. There is no timeout of its own: reading is local to PHP and the byte limit protects against large files. Slow external storage is limited to PHP's `max_execution_time`.

### Images (`files_image_view`, `files_images_view`, `files_image_search`)

Read-only tools (`files.read`) that honor the user's folder and the native Nextcloud permissions:

- **`files_image_view`**: Generates a downscaled preview of the image through `OCP\IPreview` with a configurable maximum dimension (`max_size`, default 1568 px). If the preview exceeds the byte limit (configurable in `image_single_max_bytes`, default 1 MB), the resolution is progressively reduced until it fits. If no preview is available for the format, it falls back to the original file for common formats (JPEG, PNG, WebP, GIF) as long as it is within the byte limit. Returns an `image` block (base64) and a `text` block with metadata (`path`, `mime`, original dimensions if available, `size`, `mtime`, `access`, `captured_at`).
- **`files_images_view`**: Takes a list of `paths` (up to 6) or a `folder` (with `limit`, default 6) and returns multiple image blocks under a shared budget (`image_batch_max_bytes`, default 4 MB). Files that exceed the budget or fail are listed in the `skipped` summary.
- **`files_image_search`**: Searches files with mimetype `image/%`, with optional filters by text (`query`), folder (`folder`), ISO 8601 modification range (`modified_after`/`modified_before`) and system tag (`tag`, including the automatic tags generated by the Recognize app). Returns only metadata ordered by `mtime` descending.

Invalid arguments, a nonexistent tool or one without a grant return the JSON-RPC error `-32602`, without distinguishing the reason. Execution failures return `isError: true` with a generic message, without physical path, content or stack trace.

## Resources (MCP Resources)

The server announces the `resources: {}` capability in both the legacy `initialize` flow and the modern `server/discover` flow.

- **`resources/list`**: exposes `mcp://guide` (Tool guide), returning the complete documentation in Markdown rendered dynamically based on the permissions granted and the apps active for the user.
- **`resources/templates/list`**: exposes parameterized URI templates:
  - `nc://files/{path}`: file in the user's storage (requires the `files.read` grant).
  - `nc://notes/{id}`: note identified by its numeric id (requires the `notes.read` grant and the Notes app enabled).
- **`resources/read`**: reading resources:
  - `mcp://guide`: delivers the formatted guide as `text/markdown`.
  - `nc://files/{path}`: reads text files (`text/*`, PDF, DOCX, ODT through text extraction identical to `files_read`, with a limit of 100,000 characters) or small binary files as a `blob` (base64) up to 512 KiB. Larger binary files are refused with an error.
  - `nc://notes/{id}`: delivers the note content as `text/markdown` (limit of 1 MiB).
  - Resources that do not exist, are not allowed or are hidden by tags (`VisibilityGuard`) return the standard error (`-32602` in the modern era, `-32002` in the legacy era) and never an empty content array.

## Packaging

In the `mcp/` directory:

```sh
./scripts/package.sh
```

Produces `build/mcp-<version>.tar.gz` with an `mcp/` root containing `appinfo/`, `lib/`, `templates/`, the asset folders that exist (`js/`, `css/`, `img/`, `l10n/`), both READMEs (`README.md` in English and `README.pt-BR.md` in Portuguese) and a production-only `vendor/`, created with `composer install --no-dev`. Tests and development dependencies stay out. The script fails, and leaves no package, when a `Util::addScript('mcp', X)` or `Util::addStyle('mcp', X)` in `templates/` or `lib/` points to a `js/X.js` or `css/X.css` missing from the package. The script needs `composer` and `php` on the packaging machine.

### Package rule: no macOS metadata

A package with `._*` (AppleDouble), `.DS_Store` or PAX headers with `com.apple.*` xattrs is refused by the App Store and may break the installation. Therefore:

- tar runs as `COPYFILE_DISABLE=1 tar --no-xattrs --exclude='._*' --exclude='.DS_Store'`. `--no-xattrs` was added because, without it, bsdtar writes `com.apple.provenance` in the PAX headers of every entry;
- the check is done by `scripts/check_package.php`, which reads the tar with its own PHP reader, because macOS's `tar -tzf` hides the `._*` entries and `PharData` does not expose the PAX headers;
- the package is refused, deleted, and the script exits with code 1, if it has:
  - a `._*` or `.DS_Store` entry;
  - a PaxHeader entry or extended PAX header;
  - a top-level entry other than `mcp/`;
  - a package in `vendor/` outside the runtime dependencies (`smalot/`, `symfony/polyfill-mbstring/`, `composer/`, `autoload.php`), such as `sabre/*` or `symfony/console`;
  - a script or style referenced by `Util::addScript('mcp', X)`/`Util::addStyle('mcp', X)` missing from the package.

To check a standalone package: `php scripts/check_package.php build/mcp-<version>.tar.gz`.

### Runtime dependencies

| Package | Use | License |
| --- | --- | --- |
| `smalot/pdfparser` | PDF text extraction in `files_read` | LGPL-3.0 |
| `symfony/polyfill-mbstring` | Dependency of pdfparser; inactive when the `mbstring` extension exists | MIT |

The license files accompany each package in `vendor/`. `vendor/autoload.php` is loaded by `lib/AppInfo/Application.php`.

## Install

The normal installation is through the Nextcloud App Store: *Apps* → search for **MCP for Cloud** → **Download and enable** (or `occ app:install mcp`). The store package is signed and updates through the app updater. The SSH installation below is the alternative, for development or for testing a local build.

### Install via SSH

Replace `<server>`, `<nextcloud>` (installation root), `<apps>` (writable apps directory, for example `custom_apps` or `apps`, according to `apps_paths` in `config/config.php`) and `<www>` (web server user, for example `www-data`).

```sh
scp build/mcp-1.0.8.tar.gz <server>:/tmp/
ssh <server>
sudo tar -xzf /tmp/mcp-1.0.8.tar.gz -C <nextcloud>/<apps>/
sudo chown -R <www>:<www> <nextcloud>/<apps>/mcp
sudo -u <www> php <nextcloud>/occ app:enable mcp
sudo -u <www> php <nextcloud>/occ app:list | grep -A1 mcp
```

In Docker, run the `occ` commands inside the container as the web server user. If PHP uses OPcache with `validate_timestamps=0`, restart PHP-FPM after copying the files.

To update a build installed through SSH: `occ app:disable mcp`, remove `<apps>/mcp`, extract the new package, `occ app:enable mcp` and `occ upgrade` if Nextcloud asks for it. The folder must be replaced whole, so no old files are left in `vendor/`. To remove: `occ app:remove mcp`.

## Server log (1.0.2)

`logs` module, read-only and for IT only: `logs_list` lists the `nextcloud.log` entries from newest to oldest and `logs_analyze` summarizes the same window.

- **Single source:** `ILogFactory::get('file')` and, if the writer is `IFileBased`, `getEntries($limit, $offset)`, the same path as the core's log reader. The app opens no file, uses no shell and no `occ`. `getEntries` only returns what is at the server's `loglevel` or above, and a malformed line becomes `null` (counted in `scan.malformed`).
- **Double gate:** `LogsAccess` (`RestrictedModule`, checked by the `ToolRegistry` before the grant, in `tools/list` and in every `tools/call`) accepts a Nextcloud admin or a member of a group in the versioned state `mcp_logs_groups`; then the `logs.read` grant, which starts off. With `log_type` syslog/errorlog/systemd the tools disappear and the column leaves the matrix; the admin block explains it.
- **Ceiling and budget:** the core API reopens the file and walks it from the end, character by character, also passing through the lines below the `loglevel`, and offers no physical budget. Therefore each response makes **a single** `getEntries` call, with window = min(5,000, 20,000 − `offset`) (without a filter, just the page): a second call would pay for the walk again and, with the log growing in the meantime, would return the same entry twice. The `readBudget` (20,000) is the depth in **eligible entries** (at the `loglevel` or above) from the end, not a physical line ceiling. The response carries `scan.scanned`, `truncated`, `budgetReached`, `readBudget`, `oldestScanned` and `nextOffset` (to continue further back); the rotated `.1` is not read. Everything still goes through OCP: the app never opens the file.
- **`since` does not end the read:** the core stamps each entry with the `microtime` of when it builds it, and concurrent workers write in any order. An entry before `since` is skipped and the window goes on to the end; while there is budget, `nextOffset` points to the end of the window, and the response never claims that nothing older exists.
- **Dates:** `since`/`until` in ISO 8601; the entry's `time` is read in the `logdateformat`/`logtimezone`; if it does not match, the entry is left out under a date filter and counts in `scan.unparsed`.
- **Output:** envelope `{"untrusted_log_data": true, "notice": …}` after a fixed notice; controls, ANSI and invisible marks removed; IP masked (`a.b.x.x`, IPv6 /48) also inside messages; IPv6 with CIDR is also reduced to /48; before the regexes, each field has a 16 KiB limit and the token that crosses the boundary is discarded whole, so half an IP is not exposed; after the mask, the message is cut at 2,000 characters; an exception with class, message, location and 10 frames `class->function` + `file:line`, without `args`.
- **Analysis:** totals by level/app/user, top 20 signatures (quotes, paths, UUIDs, hashes and numbers become `#`), top 10 URL paths (without query; the WebDAV path folded into endpoint + account), top 10 user agents and a histogram per hour.
- **Audit:** the `ToolRegistry` hands every call to a `RestrictedModule` with the result (`denied` by role or grant, `invalid`, `read_error`, `success`), and the module fires `CriticalActionPerformedEvent`, recorded by admin_audit when active: `MCP logs read by %s: tool=%s filters=%s` only for a read actually done and `MCP logs request %s for %s: tool=%s filters=%s` for the others, never a denial as a read. Of the arguments only the known filters remain, scalar and truncated. In the app log, a denial is `warning` and the rest `info`. If the audit itself fails (dispatcher or logger), the registry writes "MCP audit failed" without arguments: a denied, invalid or errored call keeps the original response; a successful read does **not** deliver the data and answers a generic error. If the fallback logger also fails, the exception is ignored and the same behavior is preserved.
- **Privacy (1.0.6):** with hidden tags configured and the option **Hide the text of log messages** on (default), the text of messages and exceptions, the user agent and the method outside the known verbs become `[hidden]`, the URL keeps only the fixed route prefix and `contains` is refused; see [Hidden tags (1.0.6)](#hidden-tags-106).
- **Admin API:** `GET`/`PUT /apps/mcp/api/logs-access` (`{groups, previous, version}`, lists of strings and a non-negative integer version), admin-only with CSRF; GET returns groups and version from the same snapshot. A new nonexistent group gives 400 without writing. A packaged native migration creates the own table `mcp_logs_groups`; the first read imports and canonicalizes the old `logs_groups` of the appconfig and removes the old key, without overwriting an already migrated state. If the cleanup fails, the read keeps the table's state and tries to remove the key again on the next reads. The `QBMapper` reads the table directly and writes with CAS (`UPDATE … SET groups=?, version=version+1 WHERE id=1 AND version=?`): zero rows changed or a busy lock gives 409 with the current state. The exclusive lock of `ILockingProvider` is an extra layer: with `filelocking.enabled=false`, the provider becomes Noop, but the CAS still protects against a revocation undone by concurrent saves or old appconfig snapshots. Ids are text: `"1"` and `"01"` are two groups.

## Locked file (1.0.5)

Notes and Files writes in the face of `files_lock` (an app up to Nextcloud 34, built in on 35). Common service: `Tools/Common/LockAwareWrite` (`ILockManager::isLockProviderAvailable` + `getLocks`), without importing files_lock classes.

- **Who blocks:** a manual lock of another person (`TYPE_USER`), an app lock (`TYPE_APP`, Text or Office) and a WebDAV client lock (`TYPE_TOKEN`). APP and TOKEN refuse even the owner. The user's own manual lock lets them write and is kept, as in the Files app; on the checkout-by-link route, which has no user session, it also refuses, as files_lock does there. Without a provider, nothing changes.
- **Where:** before the share confirmation and the backup, and again right before each write (`LockAwareWrite::run`): `putContent`, move, rename and delete of a note, each item of a batch and of an undo, version restore, checkout upload, content of a new file and backup copy. The storage's refusal (`ManuallyLockedException`) is also captured and explained (`capture`, used in the copy, which only reads the source).
- **In doubt, refuse:** a lock query that fails refuses; a folder being moved is checked with everything the user reaches inside it, up to 5,000 items (`LockAwareWrite::TREE_LIMIT`), and a listing larger than the remaining budget refuses without reading the lock of the items. The listing of each folder is read whole before this comparison: `getDirectoryListing()` has no limit on 32–35 and `Folder::search()`, which has one, queries the file cache and does not reproduce the listing. A file hidden by the `VisibilityGuard` is not named and the plan's `lock` carries only `blocking: true`.
- **Plan (`confirm` absent):** it does not refuse; it adds `lock` and a `file_locked` notice in `warnings`. In `files_move_batch` the locked item goes to `denied`, and the whole batch is refused.
- **Version restore:** with a provider, it reads the version through `IVersionManager::read()` and writes through the file, without the core's `rollback()`, whose `handleAppLocks` repeats the write in the Text/Office scope. The guarantee about the replaced content is the backup in `/MCP backups`.
- **Checkout:** lock → 423. Before the link is spent, the link remains valid; afterwards, the response cites the backup and asks for a new `files_checkout`.
- **Message:** app or person (display name; never UID, app id or token), since when, until when and what to do, in en/pt_BR/es. A refusal without a safe correlation by the token keeps the holder unidentified. Log only at `debug`, without path or token.
- **Limit:** TOKEN is only refused by the MCP check, which is not atomic with the write, and the files_lock `getLocks()` answers from the request cache, including a negative one: a TOKEN lock created after the first query of the file in the same request is not seen. USER and APP locks are also enforced by the files_lock storage, which reads the lock again at the write, except on deleting or renaming to/from Markdown a Text document: the Text listener (`BeforeNodeDeletedListener`/`BeforeNodeRenamedListener`) runs before the storage and does `resetDocument(..., true)`, releasing the APP lock; a Text APP lock created after the MCP query is released that way and does not prevent the deletion or rename. The MCP only refuses the lock the provider observes. files_lock offers no atomic lock acquisition (separate query and insert, `file_id` without a unique index), so the app takes no lock of its own.

## Hidden tags (1.0.6)

The `VisibilityGuard` applies system-tag hiding on every access path of the connector: reading, search, listing, tree, images, versions, notes, Talk, resources, checkout, writes and undo.

- **Inheritance:** a tag on a file or folder covers all descendants. An item reached through a local share inherits the tags of the owner's source folder chain (storage `OCP\Files\Storage\ISharedStorage` + id in the filecache, resolved once per shared root). Team folders, external storage and federated shares follow the mounted path, without querying the owner; a federated one is checked only by the local tags — those of the remote server are not readable from here.
- **Fail-closed:** a configuration that cannot be validated (the manager failing while the list is saved, or a stored value that is unreadable or invalid) keeps the previous configuration and refuses access, instead of turning the protection off; the admin page warns and offers the repair button, and saving the empty list asks for confirmation. Visibility decisions are invalidated after moving, creating or copying, so no old state is answered.
- **Logs:** with tags configured and the option **Hide the text of log messages** on (default; key `logs_hide_file_names`), the log tools hide the text of messages and exceptions, the user agent and the method outside the known HTTP/WebDAV verbs, reduce the URL to the fixed route prefixes and refuse the `contains` filter, which would match the hidden text. Turned off, the log comes back complete for diagnosis — the option exists because IT teams read exactly those details to map problems. The app's own error log records only the class and the code of the exception, never the message, on any installation.
- **Limits:** the protection applies to this MCP connector, not to other AIs or connectors that read the same Nextcloud; a loose name that another app writes in a structured field of a log entry is not handled; and the E2E with real shares (local, team folders, external storage, federated) is done on a real Nextcloud test instance, in addition to the unit suite.

## Configure

1. **Administration → MCP for Cloud** (its own entry, with an icon, in the settings menu): in the **Overview** tab, check *MCP service enabled*; in the **Access and permissions** tab, use the **Users** list (the default) or the users × permissions **Matrix (advanced)**.
   - The page has five tabs, each one reachable by keyboard and by address (`#overview`, `#access`, `#clients`, `#protection`, `#advanced`); a link opens the tab it names, and an unreadable exclusion setting is flagged in the Overview with a way to the repair.
   - The **Status** block of the Overview shows the endpoint (with copy), the service, the app version, how many users can connect and how many are connected (links to `#access?filter=eligible` and `#access?filter=connected`) and the active OAuth connections (a link to the list). **How access works** explains "Can connect", personal activation, app permissions and OAuth connections. The **checkout upload limit** (`files_checkout`) is in the **Uploads** block of Advanced, editable in MiB, with the effective limit and the PHP `post_max_size` ceiling next to it.
   - Each block says under its heading whether it saves at once or needs its Save button (`data-save-mode` in the template: `immediate`, `button`, `mixed` or `none`).
   - The other tabs: **Access and permissions** (users list, detail panel and matrix), **Clients and connections** (**OAuth clients** and **Active connections**), **Data protection** (**Excluded from MCP access**) and **Advanced** (**Server log**, **Log privacy**, **Uploads** and **OCR**).
   - **Users** (`js/admin-users.js`) is the default view of Access and permissions: one row per user with name, ID, *Can connect*, personal activation, an access summary and **Edit**; the view is kept in the address (`#access?view=matrix` for the matrix). **Edit** (or `#access?user=<uid>`) opens a modal panel loaded from `GET /apps/mcp/api/grants/{uid}` (the matrix row plus the user's groups and the number of live OAuth connections): what still stops the user, *Can connect*, then one fieldset per app with each operation, its hint and, when it cannot be changed, the reason in words. Every change saves at once through `PUT /apps/mcp/api/grants/{uid}` and shows "Saved" next to the control; closing the panel refreshes the list and gives the focus back to the user's Edit button. Both views share the toolbar, the filters and the page of users. The server log groups and the exclusion tags are chosen in searchable lists with a summary.
   - The search looks by name, ID or email, and there is a filter by group and by "only who can connect / only connected" (done on the server, with an exact total; the list starts with the users who can connect); the "Show apps" selector shows one app at a time (kept in the address as `#access?app=<module>`, an unknown module falls back to all apps) and is applied in the browser only, with no new request parameter; the table shows 50 users per page, with counter and pagination above and below. There are optional compact rows (remembered in the browser).
   - Each checkbox saves immediately: *Can connect* enables the user, and the other columns are the operations per module; the header and the column of names stay fixed when scrolling, modules alternate in bands and each operation has a hint of what it allows. Every cell reads Allowed, Denied or Unavailable. When the account is disabled or *Can connect* is off, one notice replaces the app-permission cells; otherwise, an unavailable app or log role is marked with ⊘ in the affected cells, with the reason on hover and for screen readers. A legend sits under the table.
   - The **All** menu in the header of each module (and of *Can connect*) allows or denies an operation, or all of the module, for the users of the current page (the default) or, chosen explicitly in the menu, for every user the filters select on every page, with a confirmation that counts the users who change, those left out because the permission is unavailable to them and those who already have the value (nothing is sent when no one changes); users with the permission unavailable are not written. *Undo* restores the previous values for 10 seconds (the timer pauses on hover and focus) and writes them back as they were, so a concurrent change made meanwhile is overwritten; one change or undo runs at a time. Connected users appear with the *connected* mark.
   - **Every filtered user** goes through `POST /apps/mcp/api/grants/bulk/filtered` (`{search, group, filter, module, operations, granted, dryRun, expected}`, admin-only with CSRF). A dry run counts on the server (`matched`, `change`, `unavailable`, `unchanged`) and writes nothing; the page shows those counts and the filters in words, and the real call sends `expected`, the `change` of the dry run: when the selection changed in between it answers 409 and writes nothing. At most 5000 users (`GrantMatrix::MAX_BULK`); a larger selection is refused and has to be narrowed. The answer lists the values it replaced, which *Undo* writes back through the page endpoint, 50 users per request.
   - Disabled users appear marked and cannot be edited. Modules whose app is not available on the server do not appear in the matrix; for a user without access to the app, the cell is disabled. The saved permissions are preserved.
   - The email is only used for the search and is never displayed.
2. **Excluded from MCP access**: select system tags so that tagged files and folders become completely invisible to the MCP tools (reading, search, listing, images, notes, talk and checkout; the server log tools only while “Hide the text of log messages” is on). Restricted or invisible tags are recommended. The tag covers all descendants, a local share inherits the hiding of the owner's source folder and, in doubt (invalid configuration, query failure), the app refuses; see [Hidden tags (1.0.6)](#hidden-tags-106).
   - Service, eligibility and personal connection start off for everyone, including administrators. Reading starts allowed; writing, deleting, transferring and sharing start denied. In Files, `files.share` (share with a person or group) and `files.link` (public link) are two separate operations, so the admin can grant one without the other; both come off. Listing shares remains a read.
   - The page uses the admin-only JSON API `GET /apps/mcp/api/grants` (`search`, `group`, `filter` = `eligible`/`connected`, `page`), `PUT /apps/mcp/api/grants/{uid}`, `POST /apps/mcp/api/grants/bulk`, `PUT /apps/mcp/api/service`, `GET`/`PUT /apps/mcp/api/checkout-limit` (`{mib}` integer ≥ 1) and, for the connections, `GET /apps/mcp/api/connections`, `DELETE /apps/mcp/api/connections/{id}` and `DELETE /apps/mcp/api/connections/users/{uid}`. For the hidden tags and the log option, `GET`/`PUT /apps/mcp/api/admin/tags` and `GET`/`PUT /apps/mcp/api/logs-privacy` (`{hideFileNames}`). No response carries a token hash.
3. **OAuth clients**: the *OAuth clients* block of the **MCP for Cloud** section edits the allowed client hosts and turns on the native client for local programs, with no terminal step. The API is `GET /apps/mcp/api/oauth-clients` and `PUT /apps/mcp/api/oauth-clients` (admin-only, with Nextcloud CSRF), accepts `hosts` and/or `nativeClientEnabled`, validates everything before writing and refuses an invalid host list instead of writing it. Turning the native client off immediately revokes the tokens already issued to it.
4. **Personal settings → MCP for Cloud**: the user themselves clicks *Connect*. The page shows the state (service, administrator permission, connection) and the list **Your connected clients** (client, connected at, expires at) with *Revoke*, through the API `GET /apps/mcp/api/my/connections` and `DELETE /apps/mcp/api/my/connections/{id}`, restricted to the user's own connections.
5. In **Personal settings → Security**, the user creates an app password. The MCP client uses HTTP Basic authentication with the user ID and that app password. The app never asks for or stores the main password.

Residual risk in writes: collisions with hidden destinations use the same generic refusal as destinations without permission, but the difference between a refusal and a possible creation still allows inferring that a path is unavailable.

Browser login through OAuth is described in [Connecting through claude.ai (OAuth)](#connecting-through-claudeai-oauth) and, for ChatGPT and Gemini CLI, in [Connecting through ChatGPT and Gemini CLI (OAuth)](#connecting-through-chatgpt-and-gemini-cli-oauth).

### Configuration keys

| Scope | Key | Values |
| --- | --- | --- |
| app `mcp` | `service_enabled` | `1` on, `0` or absent off |
| user, app `mcp` | `eligible` | `1` enabled by the administrator |
| user, app `mcp` | `connected` | `1` personal connection active |
| user, app `mcp` | `grant_<module>_<operation>` | `1`/`0`; absent means `1` for `read` (except `logs`, which means `0`) and `0` for the others |
| own table | `mcp_logs_groups` | one row of groups (canonical JSON) and version; empty list = administrators only; editable in the **Server log** block; imports the legacy appconfig on the first read |
| app `mcp` | `oauth_client_hosts` | comma-separated list of hosts; absent or empty means the default `claude.ai,chatgpt.com` |
| app `mcp` | `oauth_native_client_enabled` | `1` enables the local native client `nextcloud-mcp-native`, `0` or absent disabled |
| app `mcp` | `checkout_max_bytes` | upload limit of `files_checkout` in bytes; default 50 MiB; editable in the Uploads block in MiB; never above PHP's `post_max_size` |
| app `mcp` | `hidden_system_tags` | JSON list of the hidden system tag IDs (`[]` = hiding off); editable in the **Excluded from MCP access** block |
| app `mcp` | `logs_hide_file_names` | `1` (default) hides the text of log messages in the log tools while hidden tags are configured; `0` shows the complete log for diagnosis |

The two OAuth keys are editable in the **OAuth clients** section of the admin page and do not need `occ`; see [Connecting through claude.ai (OAuth)](#connecting-through-claudeai-oauth).

The `enabled` key of the `mcp` app belongs to Nextcloud, which uses it to mark the app as enabled (`yes`), and is not the MCP on/off switch. The app never reads or writes `enabled`, `installed_version`, `types`, `levels` or `ocsid`. For diagnostics: `occ config:app:get mcp service_enabled`.

## Connecting through claude.ai (OAuth)

In claude.ai, under **Customize > Connectors > Add custom connector**, paste the URL shown on the personal page (e.g. `https://<instance>/apps/mcp/`), with the trailing slash, and leave the OAuth client on **published Claude identity (CIMD)**. Claude opens the Nextcloud login and the "Allow" screen; allowing is equivalent to turning on the personal connection. Everything lives under `/apps/mcp`, with no Apache/DNS change:

- `401` with `WWW-Authenticate: Bearer resource_metadata=".../apps/mcp/.well-known/oauth-protected-resource"`;
- authorization server metadata at `/apps/mcp/.well-known/openid-configuration` and `/apps/mcp/.well-known/oauth-authorization-server` (issuer `https://<instance>/apps/mcp`);
- `oauth/authorize` (consent) and `oauth/token` (PKCE S256, refresh with rotation). Access token 1 h, refresh 30 days; only hashes go to the database.

Only CIMD clients with a host on the allowlist are accepted. The default allowlist is `claude.ai,chatgpt.com` and is edited without a terminal in **Administration → MCP for Cloud → OAuth clients → Allowed client hosts** (exact host, without `https://`, port, path or `*`, at least one; saving replaces the whole list and removing a host refuses the client immediately, even with the document cached). The `oauth_client_hosts` key does not need to be written by hand: a value saved before 0.8.0 is preserved and the default only applies when the key is absent or empty. The same section turns on **Allow local programs (native client)**, which enables the fixed public `client_id` `nextcloud-mcp-native` (off by default) for local programs such as Gemini CLI, and shows the accepted loopback redirect URIs.

Every redirect back to a client carries `iss` with the issuer (RFC 9207), including on denial and redirectable errors; errors before the redirect validation remain local. Clients without an identity document of their own, such as Gemini CLI, are refused with `invalid_client` while the native client is off.

A personal Disconnect, loss of eligibility (including in a batch by group), service turned off and a disabled or deleted account immediately delete the access/refresh tokens and pending codes. Re-enabling the service or the eligibility does not restore old credentials; connect again. Claude comes from `160.79.104.0/21`; if the Nextcloud brute-force protection slows down the token endpoint, allow that range. Basic + app password keeps working for other clients.

## Connecting through ChatGPT and Gemini CLI (OAuth)

User steps, with the same URL and the same "Allow" screen, are in the root README, in the ChatGPT and Gemini CLI sections. A summary of what the server requires from each:

- **ChatGPT**: CIMD at `chatgpt.com`, accepted by the default allowlist. The real document carries `token_endpoint_auth_methods_supported: [none, private_key_jwt]` and the singular `private_key_jwt`; the plural wins, and `none` is in the list, so the client is accepted as public.
- **Gemini CLI**: it does not publish an identity document, so it uses the static native client. The user pastes the `client_id` shown in the admin section into `~/.gemini/settings.json` → `mcpServers.<name>.oauth.clientId` and runs `/mcp auth`. The redirect `http://localhost:<port>/oauth/callback` matches because `RedirectUriMatcher` ignores the port on loopback (RFC 8252 7.3).

`iss` is mandatory because Gemini CLI rejects a return without it.

## Revoking

*Disconnect* on the personal page blocks the next MCP request, even with the same app password. The app password remains valid in Nextcloud and must be revoked under **Security**. On the same page, *Revoke* cuts a specific OAuth client of the user themselves. The administrator can also remove the user's eligibility or turn the service off; both apply on the next request. In the **Active connections** block, the administrator revokes a specific OAuth connection, or all of a user's, immediately; the user remains enabled and can sign in again.

## Manual test after installation

```sh
URL=https://<instance>/apps/mcp/
AUTH='<user>:<app-password>'
H='-H Content-Type:application/json -H Accept:application/json,text/event-stream'

curl -s -u "$AUTH" $H -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"curl","version":"1"}}}' "$URL"
curl -s -o /dev/null -w '%{http_code}\n' -u "$AUTH" $H -H 'MCP-Protocol-Version: 2025-06-18' -d '{"jsonrpc":"2.0","method":"notifications/initialized"}' "$URL"   # 202
curl -s -u "$AUTH" $H -H 'MCP-Protocol-Version: 2025-06-18' -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}' "$URL"
curl -s -u "$AUTH" $H -H 'MCP-Protocol-Version: 2025-06-18' -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"mcp_status","arguments":{}}}' "$URL"
```

Expected responses:

| Situation | HTTP |
| --- | --- |
| No credential, invalid credential or disabled user | 401 with `WWW-Authenticate: Basic` |
| Service off, ineligible or disconnected user | 403 |
| `Origin` of another host | 403 |
| Missing `Accept: application/json, text/event-stream` or `Content-Type: application/json` | 406 |
| Unknown or malformed `MCP-Protocol-Version`: answers `UnsupportedProtocolVersionError` (`-32022`, `data.supported`). The legacy versions accepted after `initialize` are 2025-03-26, 2025-06-18 and 2025-11-25; an absent header means 2025-03-26, and on `initialize` the header is ignored. Also 400 when `MCP-Protocol-Version`, `Mcp-Method` or `Mcp-Name` diverge from the body (`-32020`). The reason for each 400 goes to the log at debug level. | 400 |
| Invalid JSON (`-32700`), batch or invalid envelope (`-32600`) | 400 |
| Unknown method (`-32601`) or invalid parameters (`-32602`) | 200 with a JSON-RPC error |
| GET or DELETE | 405 |

## Development

```sh
composer install
vendor/bin/phpunit
```

The tests are unit tests, with mocks of the OCP interfaces (`nextcloud/ocp` stable33) and an in-memory file tree (`tests/Unit/Tools/FakeTree.php`). They do not replace installation on a real Nextcloud, which is the only place where share ACL, version creation, trash bin and external storage are verified.

## License

AGPL-3.0-or-later, with the additional section 7(b) attribution term in [NOTICE](NOTICE). See [LICENSES/AGPL-3.0-or-later.txt](LICENSES/AGPL-3.0-or-later.txt) for the complete license. Preserve the attribution in the administration page, documentation and copyright notices when redistributing or providing modified versions over a network.

## Credits

Smalot PdfParser, Symfony Polyfill Mbstring and the Composer autoloader are bundled. See [THIRD-PARTY-NOTICES.md](THIRD-PARTY-NOTICES.md) for versions, copyright, licenses and separate development credits.

**Contributors.** Thanks to @NightDragon1 (#5) for the root visibility check and to @matt-ek (#4) for the report behind the hidden-tags audit.

## About Sintax

Developed by [Sintax](https://sintax.tech) · sintax.tech. Contact: contato@sintax.tech.
