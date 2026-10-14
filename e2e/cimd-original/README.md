<!--
SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# URL client_id (CIMD) on the original plugin (1.0.8)

Question: with the unmodified app (commit `a1a7911`), does a self-hosted harness that uses a URL as its `client_id`
get its own redirect URI accepted?

**Answer: yes, with no code change, as long as:**

1. the administrator adds the harness host to *OAuth clients → Allowed client hosts*;
2. the `client_id` URL is `https` and serves a Client ID Metadata Document whose `client_id` equals that URL, with
   `redirect_uris` and `token_endpoint_auth_method: none`;
3. Nextcloud can download it: the host must resolve to a public address (Nextcloud's HTTP client refuses
   private/local addresses unless `allow_local_remote_servers` is `true`) and present a certificate Nextcloud trusts.

Nothing is registered or stored: the app downloads the document on demand (cached up to an hour) and accepts only
the `redirect_uris` listed in it, compared exactly. Removing the host from the allowlist refuses the client at once.

## Setup used

- Nextcloud 32.0.15 (official Docker image, SQLite), app built from `a1a7911` with `mcp/scripts/package.sh`.
- `harness_server.py`: HTTPS server for `harness.test:8443` (certificate from a test CA imported with
  `occ security:certificates:import`) serving `/client.json` and receiving `/oauth/callback`:

  ```json
  {"client_id": "https://harness.test:8443/client.json", "client_name": "My Self-Hosted Harness",
   "redirect_uris": ["https://harness.test:8443/oauth/callback"], "token_endpoint_auth_method": "none"}
  ```
- `cimd_e2e.js`: Playwright + Node script driving the admin page, Alice's login and consent, the token endpoint and
  an MCP call.

The sandbox the test ran in resets Chromium TLS connections that carry the SNI `harness.test` (curl and Nextcloud
are not affected), so the script records the callback URL the browser is sent to and delivers it to the harness
with curl.

## Results

```
PASS  original plugin: OAuth client settings have no redirect URI setting of their own  — hosts, hostsDefault, nativeClientEnabled, nativeClientId, nativeRedirectUris
PASS  default allowlist: URL client_id on harness.test is refused before any download
PASS  admin adds harness.test to Allowed client hosts  — ["claude.ai","chatgpt.com","harness.test"]
PASS  harness on a private IP: Nextcloud refuses to download the document (SSRF guard)  — allow_local_remote_servers=false
PASS  Nextcloud downloads the harness document from the client_id URL  — 2026-10-14T18:19:28.025828Z 172.17.0.2 GET /client.json UA=Nextcloud Server Crawler
PASS  consent page shows the harness name, its client_id host and the return host
PASS  Allow sends the browser to the published redirect URI and the harness receives code, state and iss  — https://harness.test:8443/oauth/callback?code=…&state=state-123&iss=http://localhost:8080/apps/mcp
PASS  code exchanges for tokens with the URL client_id and no secret  — 200 token_type=Bearer scope=mcp
PASS  MCP files_list with the harness's token returns alice's files  — 200
PASS  refresh works for the URL client_id  — 200
PASS  redirect URI not in the document is refused (other path on the harness)  — https://harness.test:8443/oauth/other
PASS  redirect URI not in the document is refused (trailing slash)  — https://harness.test:8443/oauth/callback/
PASS  redirect URI not in the document is refused (other host)  — https://evil-harness.test:8443/oauth/callback
PASS  no redirect URI is written to the app configuration  — oauth_client_hosts is the only OAuth key stored
PASS  native client settings are untouched
PASS  removing harness.test from the allowlist refuses the client again

ALL CHECKS PASSED
```

Harness log (the authorization code is redacted):

```
2026-10-14T18:19:28.025828Z 172.17.0.2 GET /client.json UA=Nextcloud Server Crawler
2026-10-14T18:20:08.014743Z 127.0.0.1 GET /oauth/callback?code=<redacted>&iss=http%3A%2F%2Flocalhost%3A8080%2Fapps%2Fmcp&state=state-123 UA=browser-redirect (forwarded by test)
```
