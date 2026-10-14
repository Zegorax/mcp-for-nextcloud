// SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
// SPDX-License-Identifier: AGPL-3.0-or-later
// Original plugin (1.0.8): does a URL client_id (Client ID Metadata Document) make the server accept the
// redirect URI the self-hosted harness publishes? Runs against the real Nextcloud and the HTTPS harness.
const { chromium } = require('playwright')
const { execSync } = require('child_process')
const crypto = require('crypto')
const fs = require('fs')
const path = require('path')

const BASE = 'http://localhost:8080'
const MCP = BASE + '/apps/mcp/'
const HARNESS = 'https://harness.test:8443'
const CLIENT_ID = HARNESS + '/client.json'
const REDIRECT = HARNESS + '/oauth/callback'
const OUT = process.env.OUT || path.join(__dirname, 'out')
const HARNESS_LOG = path.join(OUT, 'harness.log')
const log = []
let failures = 0

function record(name, ok, detail) {
	if (!ok) failures++
	const line = `${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  — ' + detail : ''}`
	log.push(line)
	console.log(line)
}
const occ = (args) => execSync(`docker exec -u www-data nc php occ ${args}`).toString().trim()
const harnessLog = () => fs.readFileSync(HARNESS_LOG, 'utf8')
const docFetches = () => harnessLog().split('\n').filter((l) => / GET \/client\.json /.test(l) && !/ 127\.0\.0\.1 /.test(l))

function pkce() {
	const verifier = crypto.randomBytes(32).toString('base64url')
	return { verifier, challenge: crypto.createHash('sha256').update(verifier).digest('base64url') }
}
function authorizeUrl(clientId, redirect, challenge, state) {
	const q = new URLSearchParams({ response_type: 'code', client_id: clientId, redirect_uri: redirect,
		code_challenge: challenge, code_challenge_method: 'S256', state, scope: 'mcp', resource: MCP })
	return `${BASE}/apps/mcp/oauth/authorize?${q}`
}
async function login(browser, user, password) {
	const context = await browser.newContext({ viewport: { width: 1280, height: 900 }, ignoreHTTPSErrors: true })
	const page = await context.newPage()
	await page.goto(BASE + '/login')
	await page.fill('#user', user)
	await page.fill('#password', password)
	await Promise.all([page.waitForURL((u) => !u.pathname.startsWith('/login')), page.click('button[type=submit]')])
	return page
}
async function token(body) {
	const res = await fetch(BASE + '/apps/mcp/oauth/token', { method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: new URLSearchParams(body) })
	return { status: res.status, json: await res.json().catch(() => null) }
}
async function mcp(accessToken, body) {
	const res = await fetch(MCP, { method: 'POST', body: JSON.stringify(body), headers: { Authorization: 'Bearer ' + accessToken,
		'Content-Type': 'application/json', Accept: 'application/json, text/event-stream', 'MCP-Protocol-Version': '2025-06-18' } })
	return { status: res.status, text: await res.text() }
}
async function adminApi(page, method, body) {
	return page.evaluate(async ({ method, body }) => {
		const res = await fetch(OC.generateUrl('/apps/mcp/api/oauth-clients'), { method,
			headers: { 'Content-Type': 'application/json', requesttoken: OC.requestToken }, body: body === undefined ? undefined : JSON.stringify(body) })
		return { status: res.status, json: await res.json() }
	}, { method, body })
}
/** Opens the authorize URL as alice; returns the harness callback URL the browser requested, or null, and the page text. */
async function authorize(page, clientId, redirect, challenge, state) {
	let received = null
	const listener = (r) => { if (r.url().startsWith(HARNESS + '/oauth/') || r.url().startsWith('https://evil-harness.test')) received = r.url() }
	page.on('request', listener)
	await page.goto(authorizeUrl(clientId, redirect, challenge, state))
	page.off('request', listener)
	return { received, text: await page.textContent('body') }
}

;(async () => {
	occ('config:system:set allow_local_remote_servers --value false --type boolean')
	const browser = await chromium.launch({ args: ['--no-proxy-server'] })
	const admin = await login(browser, 'admin', 'Adm1n-Pass-2026!')
	const alice = await login(browser, 'alice', 'Alice-Pass-2026!')
	await admin.goto(BASE + '/settings/admin/mcp#clients')
	await admin.waitForSelector('#mcp-oauth-hosts')
	const initial = await adminApi(admin, 'GET')
	record('original plugin: OAuth client settings have no redirect URI setting of their own', !('customRedirectUris' in initial.json), Object.keys(initial.json).join(', '))

	// 1. Default allowlist: the harness host is not trusted, its document is never downloaded.
	let p = pkce()
	let r = await authorize(alice, CLIENT_ID, REDIRECT, p.challenge, 's1')
	record('default allowlist: URL client_id on harness.test is refused before any download',
		r.received === null && /Unknown or not allowed client/.test(r.text) && docFetches().length === 0)
	await alice.screenshot({ path: path.join(OUT, '01-refused-host-not-allowed.png') })

	// 2. Admin adds harness.test to "Allowed client hosts" through the UI.
	await admin.fill('#mcp-oauth-hosts', 'claude.ai, chatgpt.com, harness.test')
	await admin.click('#mcp-oauth-hosts-save')
	await admin.waitForFunction(() => /Saved/.test(document.getElementById('mcp-oauth-hosts-status').textContent))
	const hosts = (await adminApi(admin, 'GET')).json.hosts
	record('admin adds harness.test to Allowed client hosts', JSON.stringify(hosts) === JSON.stringify(['claude.ai', 'chatgpt.com', 'harness.test']), JSON.stringify(hosts))
	await admin.locator('#mcp-block-oauth').screenshot({ path: path.join(OUT, '02-admin-allowed-hosts.png') })

	// 3. Nextcloud's SSRF guard refuses a document on a private address (this test harness is on 172.17.0.1).
	p = pkce()
	r = await authorize(alice, CLIENT_ID, REDIRECT, p.challenge, 's2')
	record('harness on a private IP: Nextcloud refuses to download the document (SSRF guard)',
		r.received === null && /Client metadata document unavailable/.test(r.text), 'allow_local_remote_servers=false')
	occ('config:system:set allow_local_remote_servers --value true --type boolean')

	// 4. Document reachable: the redirect URI it publishes is accepted.
	p = pkce()
	r = await authorize(alice, CLIENT_ID, REDIRECT, p.challenge, 'state-123')
	const fetches = docFetches()
	record('Nextcloud downloads the harness document from the client_id URL', fetches.length >= 1, fetches[0])
	record('consent page shows the harness name, its client_id host and the return host',
		/Allow My Self-Hosted Harness to access MCP/.test(r.text) && /Client: harness\.test/.test(r.text) && /Returns to: harness\.test/.test(r.text))
	await alice.screenshot({ path: path.join(OUT, '03-consent.png') })
	let callback = null
	const onReq = (req) => { if (req.url().startsWith(REDIRECT)) callback = req.url() }
	alice.on('request', onReq)
	await alice.click('button[value=allow]')
	await alice.waitForEvent('requestfailed', (req) => req.url().startsWith(REDIRECT)).catch(() => null)
	alice.off('request', onReq)
	const cb = new URL(callback)
	// This sandbox resets Chromium's TLS connections that carry the SNI harness.test, so the URL the browser was
	// sent to is delivered to the harness with curl, exactly as the browser would have requested it.
	execSync(`curl -s --noproxy '*' --cacert ${path.join(OUT, 'ca.pem')} -A 'browser-redirect (forwarded by test)' -o /dev/null '${callback}'`)
	const harnessSawCallback = harnessLog().split('\n').some((l) => l.includes(' GET /oauth/callback?') && l.includes('state=state-123'))
	record('Allow sends the browser to the published redirect URI and the harness receives code, state and iss',
		cb.searchParams.get('code') && cb.searchParams.get('state') === 'state-123' && cb.searchParams.get('iss') === BASE + '/apps/mcp' && harnessSawCallback,
		`${cb.origin}${cb.pathname}?code=…&state=${cb.searchParams.get('state')}&iss=${cb.searchParams.get('iss')}`)

	// 5. The harness exchanges the code (public client, PKCE) and calls MCP as alice.
	const tokens = await token({ grant_type: 'authorization_code', code: cb.searchParams.get('code'), code_verifier: p.verifier,
		client_id: CLIENT_ID, redirect_uri: REDIRECT, resource: MCP })
	record('code exchanges for tokens with the URL client_id and no secret', tokens.status === 200 && !!tokens.json.access_token,
		`${tokens.status} token_type=${tokens.json?.token_type} scope=${tokens.json?.scope}`)
	const files = await mcp(tokens.json.access_token, { jsonrpc: '2.0', id: 1, method: 'tools/call', params: { name: 'files_list', arguments: { path: '/' } } })
	record("MCP files_list with the harness's token returns alice's files", files.status === 200 && /harness-proof\.txt/.test(files.text), String(files.status))
	const refreshed = await token({ grant_type: 'refresh_token', refresh_token: tokens.json.refresh_token, client_id: CLIENT_ID })
	record('refresh works for the URL client_id', refreshed.status === 200, String(refreshed.status))

	// 6. Only the URIs in the document are allowed.
	for (const [label, uri] of [['other path on the harness', HARNESS + '/oauth/other'], ['trailing slash', REDIRECT + '/'],
		['other host', 'https://evil-harness.test:8443/oauth/callback']]) {
		p = pkce()
		r = await authorize(alice, CLIENT_ID, uri, p.challenge, 'sx')
		record(`redirect URI not in the document is refused (${label})`, r.received === null && /redirect_uri is not registered/.test(r.text), uri)
	}
	await alice.screenshot({ path: path.join(OUT, '05-refused-uri-not-in-document.png') })

	// 7. Nothing is registered or stored: the URIs come from the document on each request (cached up to an hour).
	const appConfig = occ('config:list mcp')
	record('no redirect URI is written to the app configuration', !/harness\.test:8443\/oauth/.test(appConfig) && /"oauth_client_hosts": "claude.ai,chatgpt.com,harness.test"/.test(appConfig),
		'oauth_client_hosts is the only OAuth key stored')
	const after = await adminApi(admin, 'GET')
	record('native client settings are untouched', after.json.nativeClientEnabled === false && JSON.stringify(after.json.nativeRedirectUris) === JSON.stringify(initial.json.nativeRedirectUris))

	// 8. Removing the host from the allowlist refuses the client at once, even with the document cached.
	await adminApi(admin, 'PUT', { hosts: ['claude.ai', 'chatgpt.com'] })
	p = pkce()
	r = await authorize(alice, CLIENT_ID, REDIRECT, p.challenge, 's-off')
	record('removing harness.test from the allowlist refuses the client again', r.received === null && /Unknown or not allowed client/.test(r.text))

	await browser.close()
	occ('config:system:set allow_local_remote_servers --value false --type boolean')
	log.push('', failures === 0 ? 'ALL CHECKS PASSED' : `${failures} CHECK(S) FAILED`)
	fs.writeFileSync(path.join(OUT, 'results.txt'), log.join('\n') + '\n')
	process.exit(failures === 0 ? 0 : 1)
})().catch((e) => { console.error(e); process.exit(2) })
