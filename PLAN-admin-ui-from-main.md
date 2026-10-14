# Plan: admin UI follow-up for sintax-tech/mcp-for-nextcloud#2 (version A, from `main`)

Base: `main` at `a1a7911` (1.0.8). Independent of the open PRs #1 (custom redirect URIs) and #2 (group
permissions). See `PLAN-admin-ui-integrated.md` for the version that builds on both.

## What 1.0.7 already delivered

The 1.0.7 release ("task categories and admin page redesign") already covers most of the issue. These items
are done and stay as they are:

| Issue item | Where it is today |
|---|---|
| Five sections (Overview, Access, Clients, Data protection, Advanced), keyboard and `#hash` navigation | `templates/admin.php`, `js/admin-tabs.js` |
| Searchable tag and log-group selectors, selected items first, summary | `js/admin-picker.js` |
| Matrix app filter (`#access?app=files`) | `js/admin-grants.js` `applyAppFilter` |
| Sticky header rows and sticky name column | `css/admin.css` (`thead th`, `.mcp-user-col`) |
| Allowed / Denied / Unavailable with ⊘ and a reason on hover and for screen readers | `paintState`, `unavailableReason` |
| Compact rows toggle, comfortable by default | `#mcp-compact` |
| "All" menu confirms how many users change, then Undo for 10 s | `bulkPlan`, `bulk`, `undoBulk` |
| Revoke one connection or all of a user's connections, search by user or client | `js/connections.js` |

## What is still missing

1. **User-focused permission editor** (the main gap). Today the matrix is the only editor; the issue asks for a
   readable user list as the default, a per-user detail panel grouped by app, and the matrix as an optional
   advanced view.
2. **Bulk action across all filtered users.** "All" is limited to the 50 users on the page (listed as a known
   limitation in 1.0.7). The issue asks for a separate, explicit action for every filtered user.
3. **Save behavior and terminology.** No visible marker of what saves at once and what needs Save; no single
   explanation of eligibility vs. personal activation vs. app permissions vs. OAuth connections.
4. **Allowed client hosts** is one comma-separated text field; the issue asks for a wider field or individually
   editable entries.
5. **Layout details.** The checkout upload limit sits in Overview; the issue puts upload limits in Advanced.
   Overview cards do not link to their sections. Block widths vary.

The authorization model does not change. Every new endpoint is admin-only and goes through `GrantPolicy`.

## Steps

Each step is one commit with its tests; translations (pt_BR, es) and the CHANGELOG `Unreleased` entry are
updated in the same commit as the strings they cover.

### Step 1: layout and Overview links (small)

- Move the "Checkout upload limit" card from Overview into Advanced, in a new "Uploads" block above OCR.
- Make the Overview cards link to their section: Eligible users → `#access?filter=eligible`, Connected users →
  `#access?filter=connected`, Active connections → `#clients`.
- Add a "How access works" block to Overview: four short definitions (Can connect, Personal activation, App
  permissions, OAuth connections) and how they combine. The same wording replaces the long hint above the
  matrix, which then links to it.
- One max width for text blocks (`.mcp-block { max-width: 900px }`), full width for the matrix and the
  connections table.
- `admin-tabs.js`: accept a `filter=` parameter in `#access?…` alongside `app=`.
- Tests: `AdminAssetsTest` (ids, moved block), `AdminOcrStatusTest` if the Advanced markup changes.

### Step 2: save-behavior markers and feedback near the control (small)

- Add a small "Saves at once" or "Needs Save" label under each block heading (`.mcp-save-mode`), driven by a
  `data-save-mode` attribute in the template so tests can assert every block has one.
- Status messages already sit next to most controls (`.mcp-status`). Add the missing ones (matrix cell errors
  currently only `flash` the cell and call `notifyError`): put the error text in the row's status cell as well,
  so it is readable without a toast.
- Tests: `AdminAssetsTest` asserts every `.mcp-block` with an editable control carries `data-save-mode`.

### Step 3: allowed client hosts as a list (small)

- Replace `#mcp-oauth-hosts` with a list editor: one row per host with Remove, an input with Add, then Save.
  Validation stays server-side (`GrantsController::updateOauthClients`); the client only splits and trims.
- Write it as a reusable `McpAdminListEditor` in a new `js/admin-list.js`, so the redirect URI list of PR #1
  can adopt it later.
- No API change: the page still sends the same comma-joined value.
- Tests: `AdminAssetsTest` (script registered, ids), existing `GrantsControllerTest` for hosts unchanged.

### Step 4: single-user endpoint (backend for step 5)

- `GET /api/grants/{uid}` → `GrantMatrix::rows([$user])[0]` plus `groups` (the user's group display names) and
  `connections` (count from `ConnectionList`). Admin-only, 400 for an unknown uid, same as `update`.
- Route order: declare it after `grants#bulk` (POST) so nothing collides; `RoutesTest` gains the route.
- Tests: `GrantsControllerTest` (found, unknown uid, disabled user, unavailable app).

### Step 5: user list and detail panel (main work)

- Access tab gets a view switch: **Users** (default) and **Matrix (advanced)**, kept in the hash as
  `#access?view=matrix`. The toolbar (search, group, filter, pager) is shared; the app filter and compact
  toggle show only in the matrix view.
- **Users view**: a table with display name, user ID, Can connect, Activated, an access summary ("Read: 6 apps,
  Write: 2, Server log: no") and an "Edit" button. Built from the same `/api/grants` page data, no new query.
- **Detail panel** (`<dialog>` or an inline side panel, opened by Edit or by `#access?user=<uid>`, loaded with
  step 4's endpoint):
  - Header: name, user ID, account state, groups, active connections (link to `#clients` with the search
    prefilled).
  - "Can connect" toggle with the personal-activation state next to it, and a line that says what is still
    missing ("Can connect, but has not turned the connection on").
  - One fieldset per app (Files, Notes, Deck, Calendar, Talk, Contacts, Tasks, People), each operation as a
    labeled checkbox with the existing `operationHint` text; unavailable operations disabled with the
    `unavailableReason` text visible, not only on hover.
  - "Server log" fieldset that explains its extra requirements (file log, membership of a log group) and links to
    the Advanced tab.
  - Every change saves at once through `PUT /api/grants/{uid}` and shows its result next to the checkbox; the
    users list row refreshes on close.
  - Focus moves to the panel title on open and back to the Edit button on close; Escape closes.
- Refactor `admin-grants.js` before adding to it (it is 1319 lines): move the matrix into `js/admin-matrix.js`
  and keep shared helpers (`api`, `el`, labels, `unavailableReason`) in `admin-grants.js` behind a small
  `window.McpAdmin` namespace. Keep this move as its own commit with no behavior change.
- Tests: `AdminAssetsTest` (ids, scripts, `view`/`user` hash handling present), a new `UserDetailTest` only if
  there is server-side rendering; the JS has no test runner today, so the real-Nextcloud check below covers it.

### Step 6: bulk across all filtered users (backend + UI)

- `POST /api/grants/bulk-filtered` with `{search, group, filter, module, operation, granted, dryRun}`:
  - Iterates every matching user server-side through `GrantMatrix` in pages of `PAGE_SIZE`, so there is no
    unbounded `uids` list in the request.
  - `dryRun: true` answers `{matched, change, unavailable, unchanged}` and writes nothing.
  - The real call must send `expected` (the `change` count from the dry run); a different count answers 409 so
    a stale preview never writes.
  - Hard cap `GrantMatrix::MAX_BULK = 5000` users; above it, 400 and the UI asks to narrow the filter.
  - Answers `{updated, previous: {uid: bool}}` so the page can offer Undo through the existing per-page
    `bulk` endpoint in chunks of 50.
- UI: the "All" menu keeps "Apply to the N users on this page" and gains a separate item
  "Apply to all M filtered users…", which runs the dry run and asks for confirmation naming the app, the
  operation and the counts.
- Tests: `GrantsControllerTest` (dry run counts, 409 on mismatch, cap, unavailable users skipped, eligibility
  pseudo-operation), `GrantMatrixTest` for the iteration helper.

### Step 7: docs and release notes

- README (en, pt-BR) and `mcp/README*.md`: the Users view, the advanced matrix, the cross-page bulk action.
- CHANGELOG `Unreleased`: Added / Changed, referencing #2; remove the 1.0.7 "All applies to the page" known
  limitation. Version bump left to the release commit.

## Verification

- `vendor/bin/phpunit` (as in `.github/workflows/tests.yml`) (full PHPUnit suite) after each step; `php -l` on changed PHP.
- Real Nextcloud 32 (official Docker image) driven by Playwright, as for the earlier PRs:
  - grant one user access from the Users view (search, Edit, Can connect, Files write, confirmation shown);
  - deep link `#access?user=<uid>` opens the panel; Escape returns focus;
  - matrix view still works, app filter and sticky headers intact;
  - cross-page bulk on a 120-user group: preview counts, 409 after a concurrent change, Undo restores;
  - add and remove an allowed host with the list editor;
  - keyboard-only pass through every tab.
- A real MCP request with an app password before and after, to confirm no permission changed on upgrade.

## Risks

- `admin-grants.js` size: the refactor in step 5 must land without behavior change; review it on its own.
- Cross-page bulk is the only change that can write many users at once; the dry run, `expected` count, cap and
  Undo are what keep it safe.
- PR #2 rewrites the matrix to three states. If PR #2 merges first, steps 5 and 6 must switch to
  `state: allow | deny | inherit` (see the integrated plan).
