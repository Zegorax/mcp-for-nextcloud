# Plan: admin UI follow-up for sintax-tech/mcp-for-nextcloud#2 (version B, on top of PRs #1 and #2)

Base: a new branch from `main` with both open PRs merged in:

- PR #1 `claude/feature-request-plan-bc9dui`: self-hosted OAuth client with custom redirect URIs.
- PR #2 `claude/group-permissions`: group permissions, individual overrides (allow / deny / inherit), the
  `GET /api/effective/{uid}` explanation, the effective-access `<dialog>`, "Your access" on the personal page,
  version 1.1.0.

See `PLAN-admin-ui-from-main.md` for the version that starts from `main` alone; the gap analysis there ("What
1.0.7 already delivered" and "What is still missing") applies here too and is not repeated.

## Implementation notes

Implemented on this branch with these differences from the plan below:

- The `admin-grants.js` split (step 5) was not done. The new code lives in new files (`js/admin-users.js`
  for the views and the detail panel, `js/admin-list.js` for the list editor); `admin-grants.js` gains a
  small `window.McpAdmin` namespace, the "every filtered user" flow, and loses PR #2's read-only
  effective-access dialog, which the editable panel replaces. There is no behavior-neutral refactor to
  review.
- Step 4: instead of an `unavailableReason` in `EffectiveAccess`, the panel derives the reasons with the
  matrix's own `unavailableReason`, and `GET /api/effective/{uid}` gains `connections` (admin only).
- `POST /api/grants/bulk/filtered` takes `operations` (a list) and `state`; its dry run returns `gain` and
  `lose` computed from the user's group rules and the defaults, so no call to `GroupGrantImpact` is needed.

## Step 0: combine the two PRs

- `git checkout -b claude/admin-ui-integrated origin/claude/group-permissions` then
  `git merge origin/claude/feature-request-plan-bc9dui`.
- A trial merge conflicts only in additive files: `CHANGELOG.md`, `mcp/css/admin.css` and the four
  `mcp/l10n/{es,pt_BR}.{js,json}` files. Keep both sides in each (both PRs only append). `admin-grants.js`,
  `GrantsController.php`, `admin.php` and the tests merge cleanly.
- CHANGELOG: PR #2 puts its entry under 1.1.0 and PR #1 under "Unreleased"; move PR #1's entry into 1.1.0.
- Drop `PLAN-custom-redirect-uris.md` from PR #1 if it should not ship.
- Run the full suite before any new work; this commit is the baseline.
- If either PR changes before this starts, redo the merge rather than cherry-picking.

## How the PRs change the remaining work

| Gap | From `main` (version A) | Here (version B) |
|---|---|---|
| Single-user data for the detail panel | new `GET /api/grants/{uid}` | reuse `GET /api/effective/{uid}` from PR #2; no new endpoint |
| Detail panel | new, editable | turn PR #2's read-only effective-access `<dialog>` into the editor |
| Cell states | Allowed / Denied | Allow / Deny / Inherit, with source (individual, group, default) |
| Bulk across all filtered users | `granted: bool` | `state: allow \| deny \| inherit`, Undo restores overrides including "inherit" |
| List editor | hosts only | hosts and PR #1's redirect URIs share one component |
| Access tab length | matrix only | matrix and Group permissions: needs sub-navigation |
| Terminology block | four terms | adds "group rule" and "individual setting" and the decision order |

## Steps

Same commit rules as version A: one commit per step with its tests, translations (pt_BR, es) and the
CHANGELOG 1.1.0 entry updated with the strings they cover.

### Step 1: layout, Overview links, "How access works" (small)

As version A step 1, plus:

- The Access tab now holds three blocks (Users, Group permissions, and the advanced matrix from step 5). Add a
  secondary switch at the top of the tab: **Users**, **Groups**, **Matrix (advanced)**, kept in the hash as
  `#access?view=users|groups|matrix`. Only the chosen block shows; without JavaScript all three stay visible.
- "How access works" includes the decision order from PR #2 (individual deny, individual allow, groups with
  deny winning, default) and links to the Groups view.

### Step 2: save-behavior markers (small)

As version A step 2. The Group permissions block from PR #2 is the one block marked "Needs Save" besides the
host and redirect lists and the upload limit; the user matrix and the detail panel are "Saves at once". The
difference is now visible on one tab, so the markers matter more here.

### Step 3: shared list editor for hosts and redirect URIs (small)

- `js/admin-list.js` (`McpAdminListEditor`): rows with Remove, an input with Add, a Save button, a
  per-row validation message, and a confirmation when removing an entry revokes sessions.
- Use it for **Allowed client hosts** (comma-joined on save, API unchanged) and for PR #1's
  **Accepted redirect URIs** (newline-joined on save, API unchanged). PR #1 revokes tokens when a URI is
  removed, so its editor confirms: "Removing this URI signs out every connection of this client."
- Tests: `AdminAssetsTest` (script, ids, the PR #1 textarea replaced), existing `CustomClientTest` and
  `GrantsControllerTest` unchanged since the payloads are the same.

### Step 4: no new single-user endpoint

PR #2's `EffectiveAccess::forUser` already returns the user's groups, blockers, and for eligibility and each
permission: result, effective, available, source, individual override and the deciding groups. Only one
addition: an `unavailableReason` per module (today computed in JS from the matrix row), so the panel does not
need the matrix page loaded. Add it to `EffectiveAccess` and its test.

### Step 5: user list and editable detail panel (main work)

- **Refactor first** (own commit, no behavior change): after PR #2, `admin-grants.js` is about 1,800 lines. Split
  into `admin-grants.js` (shared helpers under `window.McpAdmin`), `admin-matrix.js` (user matrix),
  `admin-groups.js` (PR #2's group matrix and preview) and `admin-effective.js` (the dialog).
- **Users view** (default): display name, user ID, Can connect (with its source), Activated, an access summary,
  a marker when the user has individual settings, and Edit. Built from the `/api/grants` page data.
- **Detail panel**: PR #2's `<dialog id="mcp-effective">` becomes editable:
  - Header: name, user ID, groups (each links to `#access?view=groups&group=<id>`), blockers from PR #2 as
    plain sentences, active connections with a link to `#clients`.
  - "Can connect" as a three-way control (Allow, Deny, Inherit from groups) that shows what the groups would
    decide ("Groups: allowed by Sales").
  - One fieldset per app, each operation as the same three-way control, the group outcome shown next to it, and
    the unavailable reason as visible text.
  - "Server log" fieldset with its extra requirements and a link to the log groups in Advanced.
  - Each change saves at once through `PUT /api/grants/{uid}` with `state`, then reloads
    `/api/effective/{uid}` so the source and "effective" columns are right; result shown next to the control.
  - Focus handling and Escape as in version A. Keep PR #2's fix that hides the closed dialog explicitly.
- **Matrix (advanced)**: PR #2's matrix as it is, behind the view switch.
- Tests: `AdminAssetsTest` (views, ids, scripts), `GroupGrantsControllerTest` for the added field, and the
  existing `GrantsControllerTest` state cases cover the writes.

### Step 6: bulk across all filtered users

As version A step 6, with these differences:

- Body takes `state` (`allow | deny | inherit`) instead of `granted`.
- The dry run reports, besides `change / unavailable / unchanged`, how many users the change actually affects
  after group rules: setting "inherit" on a user whose groups allow is not a loss of access. Compute it with
  `GrantPolicy::explain` before and after, reusing the logic of PR #2's `GroupGrantImpact`.
- The response's `previous` map holds `true | false | null` per user so Undo restores "inherit" correctly
  through the existing `bulk` endpoint with `state`.
- When the change removes "Can connect" from anyone, the confirmation says how many will be signed out, as
  PR #2's group preview already does.
- Tests: as version A, plus the inherit cases and the "effective change" count with group rules present.

### Step 7: docs and release notes

- README (en, pt-BR) and `mcp/README*.md`: the three Access views, the editable detail panel, the cross-page
  bulk action, the list editors.
- CHANGELOG 1.1.0: one combined entry (group permissions, self-hosted clients, admin UI follow-up for #2);
  remove the 1.0.7 known limitation about "All".

## Verification

- `vendor/bin/phpunit` (as in `.github/workflows/tests.yml`) after the merge (baseline) and after each step.
- Real Nextcloud 32 (official Docker image), upgraded in place from 1.0.8 with `occ upgrade`, driven with
  Playwright and checked with real MCP requests per user, as PR #2 did:
  - everything listed in version A's verification;
  - grant one user access from the panel while a group denies it: the panel shows the deny wins;
  - set a user back to Inherit from the panel: source switches to the group;
  - cross-page bulk "inherit" on a group: dry-run counts match the real outcome; Undo restores overrides;
  - PR #1's flow still passes with the list editor (add URI, sign in, remove URI revokes);
  - PR #2's 20 checks still pass.

## Risks

- Larger base: the work depends on two unmerged PRs. If upstream rejects or reshapes either one, steps 4–6 need
  rework; version A has no such dependency.
- The detail panel writes overrides; an admin can easily create individual settings that mask group rules. The
  panel should always show the group outcome next to the control and offer Inherit first.
- Same `admin-grants.js` refactor risk as version A, but larger; keep the split as its own reviewed commit.

## Choosing between A and B

- Pick **A** to send something upstream now, independent of the two open PRs; it is smaller and reviewable
  alone, but steps 5 and 6 will need a second pass once PR #2 lands.
- Pick **B** if PRs #1 and #2 are expected to merge first or ship together as 1.1.0; the detail panel then
  builds on PR #2's explanation instead of duplicating it.
