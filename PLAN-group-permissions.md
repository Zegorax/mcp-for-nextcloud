# Plan: group permissions and effective access (sintax-tech/mcp-for-nextcloud#3)

## Starting point

- Fork `main` = upstream `main` = `a1a7911` (1.0.8). Upstream has no work in progress for #3.
- **PR #2** (`claude/group-permissions`, 3 commits, 46 files, +2793/-127) already implements #3 from `main`:
  `GroupGrants`, `GroupGrantsMapper` + migration, `GrantPolicy::explain()`, `GroupGrantImpact` (preview),
  `EffectiveAccess`, `GroupChangeListener`, new API routes, admin group matrix, "Your access" on the personal page,
  version 1.1.0.
- **PR #1** (`claude/feature-request-plan-bc9dui`, 2 commits, 28 files, +656/-29) adds the self-hosted OAuth client
  `nextcloud-mcp-custom` (upstream #7). Unrelated to #3 in logic; touches the same admin page, controller,
  translations and docs.

So both versions below are "finish and land PR #2", differing only in whether PR #1 is folded in.

## Gaps between PR #2 and the issue (apply in both versions)

| # | Issue item | PR #2 today | Action |
|---|---|---|---|
| G1 | Groups are grant / no-grant in the first version; group-level allow/deny conflicts are deferred | Groups can allow **and** deny; a deny in any group wins | **Decision needed.** Keep (needed for a group to restrict a read that is allowed by default, and safer: deny wins) and say so in the PR as a deliberate superset; or cut group deny to match the issue exactly (drop the deny state in `GroupGrants`, the UI cycle and the related tests). Recommended: keep. |
| G2 | Review step offers "an easy way to inspect the effective access of affected users" | Preview gives counts per permission; effective access opens from the user matrix | Verify; if the preview only shows counts, add an expandable list of affected members (gain / lose / own setting), each opening `openEffective(uid)`. Cap the list (e.g. 50) with a "and N more". |
| G3 | Tests: multiple groups, overrides, removal | Covered (`GroupPermissionResolutionTest`, `GroupGrantsControllerTest`, mapper test) | None, beyond keeping them green. |
| G4 | CI | Never ran on the fork (Actions off) | Enable Actions on the fork, or run the CI command locally on PHP 8.2 and 8.5 (see Validation). |

Everything else in the issue (resolution order, three-state overrides, blockers vs missing grant, activation,
app unavailable, membership changes on the next request plus immediate sign-out when connect is lost, preview
before save, migration that changes nobody's access, "who overrides the group" marker, explicit ↺ to inherit) is
already in PR #2.

---

## Version A: integrate PR #1 and PR #2

Goal: one 1.1.0 containing group permissions (#3) and the self-hosted client (#7).

### Steps

1. Branch from `main` (this branch, `claude/mcp-nextcloud-plan-4v3bkr`).
2. `git merge origin/claude/group-permissions` (fast-forward).
3. `git merge origin/claude/feature-request-plan-bc9dui`. A trial merge gives 6 conflicts:
   - `CHANGELOG.md`: move PR #1's "Unreleased / Added" entry into `## 1.1.0 / Added`; drop "Unreleased".
   - `mcp/css/admin.css`: both append at the end of the file; keep both blocks.
   - `mcp/l10n/{es,pt_BR}.{js,json}`: both append keys to the same object; take the union, fix the trailing comma,
     and check each `.js`/`.json` pair has the same keys (`php -r 'json_decode(...)'` + a key diff).
4. Review the files that auto-merged but are touched by both PRs:
   - `mcp/js/admin-grants.js`: #2 rewrites the matrix, #1 adds the custom-client controls to the OAuth section;
     check both init paths run and no helper was renamed under the other.
   - `mcp/templates/admin.php`: OAuth block (#1) and the group block + `<dialog id="mcp-effective">` (#2).
   - `mcp/lib/Controller/GrantsController.php`: different methods (#1 `oauthClients`/`setOauthClients`, #2 grant
     `state`); confirm `use` lines and the constructor are intact.
   - `README*.md`, `mcp/README*.md`, `AdminAssetsTest`, `GrantsControllerTest`.
5. Check the cross-feature behaviour:
   - `AccessTokenAuthenticator`: `ClientResolver::builtInDisabled()` (#1) runs before `TokenOwnerGate::owner()`,
     which now resolves groups (#2). Both must reject; order does not matter.
   - `GroupChangeListener` calls `OAuthStore::deleteForUser()`, which removes custom-client tokens too: add one test
     that a user losing "Can connect" through a group loses a `nextcloud-mcp-custom` token.
   - `ConnectionList` / `connections.js` (#1 label "Self-hosted client") and the effective-access view (#2) do not
     overlap; no change expected.
6. Delete `PLAN-custom-redirect-uris.md` (and this file) before upstreaming.
7. Apply G1–G2.
8. Validate (below), commit, push.

### Landing options

- **A1 (recommended):** land in sequence on the fork: merge PR #2 into `main`, then merge `main` into PR #1's branch
  with the resolutions above and merge it. Two reviewable PRs, same end state.
- **A2:** one combined PR from this branch, closing #1 and #2. Simpler to test once, harder to review and to
  upstream as two features.

Upstream: the maintainer asked to develop #3 alongside their #2 roadmap; two separate upstream PRs (one per issue)
are likelier to be accepted than one combined.

---

## Version B: from `main`, current state only

Goal: #3 alone on top of 1.0.8, PR #1 untouched.

### Steps

1. Use PR #2's branch as the base (it is already built from `main` and its description reports 5146 passing tests); no rewrite needed.
   If a clean re-implementation is wanted instead, follow the same three-commit order PR #2 used:
   1. backend: `mcp_group_grants` table (one versioned row, compare-and-set, 409 on stale), `GroupGrants`
      (rules once per request, groups once per user), `GrantPolicy` resolution (individual deny > individual allow >
      groups > default), `explain()`, upgrade-equivalence test;
   2. API + UI: group routes, preview (`GroupGrantImpact`), `EffectiveAccess`, `/api/my/access`, admin group matrix,
      matrix source tooltips and ↺, personal "Your access", `GroupChangeListener`, version 1.1.0, l10n, docs;
   3. layout polish.
2. Apply G1–G2 on top.
3. `git merge main` if `main` moved; validate; push.
4. Afterwards PR #1 needs `main` merged in, with exactly the conflict list of Version A step 3, and the CHANGELOG
   entry placed under the next version (1.1.0 if not yet released, else "Unreleased").

---

## Validation (both versions)

- `cd mcp && composer install && php -d memory_limit=1G -d post_max_size=1G -d upload_max_filesize=1G vendor/bin/phpunit`
  on PHP 8.2 and 8.5 (CI matrix is 8.2-8.5). The `post_max_size` flag avoids the two known Files upload failures.
- Real Nextcloud 32 (Docker), `occ upgrade` from 1.0.8: rerun PR #2's 20 Playwright checks; for Version A also
  PR #1's 19-check OAuth flow, plus: custom client user removed from the only allowing group gets 401 on the next
  MCP request.
- Re-read the diff for leftover conflict markers and l10n key parity before pushing.

## Open decision

G1: keep group-level deny (recommended) or restrict groups to grant / no-grant as the issue's first version says.
