<!--
SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Plan: custom redirect URIs for self-hosted harnesses

Feature request: [sintax-tech/mcp-for-nextcloud#7](https://github.com/sintax-tech/mcp-for-nextcloud/issues/7).
Self-hosted harnesses that support MCP OAuth return to their own domain, not to localhost.

## Current state

- `ClientResolver` accepts two kinds of client: a Client ID Metadata Document (CIMD) whose host is in
  `oauth_client_hosts`, or the native client `nextcloud-mcp-native`, whose redirect URIs are fixed to loopback
  (`mcp/lib/OAuth/NativeClient.php`).
- A harness that publishes its own CIMD already works today: the administrator adds its host to
  **Allowed client hosts**.
- The gap: most self-hosted harnesses do not publish a CIMD. They take a manually configured `client_id`, or
  expect dynamic client registration (RFC 7591), which the app does not offer. The only manual `client_id` is the
  native client, and it is loopback-only.

## Approach

A second static public client, `nextcloud-mcp-custom`, whose redirect URIs are an administrator-managed list of
exact HTTPS URIs. Keeping it separate from the native client keeps the consent label and loopback warning of the
native client accurate and lets each be revoked independently. Security properties are unchanged: PKCE S256
required, exact redirect matching, the list under administrator control.

## Steps

1. **`mcp/lib/OAuth/CustomClient.php`** (new): `CLIENT_ID = 'nextcloud-mcp-custom'`, `ENABLED_KEY`,
   `REDIRECTS_KEY = 'oauth_custom_redirect_uris'` (JSON list), `metadata(IConfig)` built from the stored list.
2. **`ClientResolver::resolve`**: route the custom client like the native one; `invalid_client` (not redirectable)
   when disabled or when the list is empty.
3. **Redirect URI validation** (static helper, used on save):
   - `https` with a host; `http` only for loopback;
   - no userinfo, fragment or `*`;
   - at most 2000 characters (the `redirect_uri` column length);
   - deduplicated, at most about 20 entries;
   - stored verbatim and matched exactly, with no normalisation.
4. **`GrantsController`**:
   - `updateOauthClients` accepts `customClientEnabled` and `customRedirectUris`, validates everything before
     writing (as it does now);
   - disabling the client or removing a URI calls `OAuthStore::deleteForClient(CustomClient::CLIENT_ID)`, so tokens
     issued for a withdrawn URI do not outlive the decision;
   - `oauthState()` returns `customClientEnabled`, `customClientId` and `customRedirectUris`.
5. **Admin page** (`mcp/templates/admin.php`, `mcp/js/admin-grants.js`): a block beside the native client with an
   "Allow self-hosted clients (custom redirect URIs)" checkbox, a textarea (one URI per line) with Save, the
   client_id with Copy, and a status line; reuses `api('PUT', '/api/oauth-clients', …)`.
6. **Consent and connection lists**:
   - `OAuthController::authorize` labels the custom client, e.g. "Self-hosted client (configured by your
     administrator)"; "Returns to: host" already shows the redirect domain;
   - `ConnectionList::describe` gains a `custom` kind so the admin and personal tables label it;
   - the CSP form-action already handles any origin through `origin()`.
7. **Translations**: new strings in `mcp/l10n/es.*` and `mcp/l10n/pt_BR.*` (`L10nSourceTextTest`, `L10nTest`).
8. **Tests** (`mcp/tests/Unit/OAuth`, `mcp/tests/Unit/Controller`):
   - resolver: disabled, empty list, enabled;
   - validator: accepts `https`; rejects non-loopback `http`, userinfo, fragment, wildcard, overlength, too many;
   - exact matching: trailing slash, extra path or changed query fail;
   - full `/authorize` → `/token` flow with a custom URI;
   - disabling the client or removing a URI revokes its tokens;
   - the admin endpoint returns 400 on bad input and writes nothing.
9. **Docs and release**:
   - `mcp/README.md` and `mcp/README.pt-BR.md`: the new keys in the configuration table and a "Self-hosted
     harnesses" subsection (client_id, how to set the redirect URI);
   - `CHANGELOG.md`; bump `mcp/appinfo/info.xml` to 1.0.9.

## Alternative considered

Dynamic client registration (RFC 7591) with a `registration_endpoint` in the authorization server metadata. It is
the most compatible with zero configuration, but it needs a new table and migration, and either allows open
registration of arbitrary redirects or needs its own allowlist: a much larger security surface. Kept as a
follow-up if a specific harness cannot take a manual `client_id`.

## Open question

Which harness prompted the request? If it accepts a manual `client_id`, this plan covers it. If it only does
dynamic registration, the scope moves to the alternative.
