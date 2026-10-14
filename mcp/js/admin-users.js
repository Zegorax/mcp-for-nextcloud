/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Users view, view switch and per-user detail panel of the "Access and permissions" tab.
 *
 * The tab shows one of three views, kept in the address (#access?view=groups|matrix): Users (the default),
 * Groups (the group permissions) and Matrix (advanced). The Users view has one readable row per user (name,
 * user ID, "Can connect" and where it comes from, personal activation, a short access summary and Edit),
 * built from the same page of GET /apps/mcp/api/grants that js/admin-grants.js loads for the matrix. Edit, a
 * name in the matrix, or the address #access?user=<uid> opens a dialog with that user's access grouped by
 * app, loaded from GET /apps/mcp/api/effective/{uid}: for "Can connect" and each operation the result, where
 * it comes from, and Allow / Deny / Inherit. Every change saves at once through PUT /apps/mcp/api/grants/{uid}
 * (state allow, deny or inherit) and shows its result next to the control. Uses window.McpAdmin from
 * js/admin-grants.js and offers window.McpAdminUsers.open to it. Plain browser JavaScript, no build step.
 */
(function () {
	'use strict'

	const root = document.getElementById('mcp-admin')
	const shared = window.McpAdmin
	if (!root || !shared) {
		return
	}
	const { api, el, notifyError, moduleLabels, operationLabels, operationHint, unavailableReason, rowUnavailableReason, decisionOf, sourceText } = shared

	const block = document.getElementById('mcp-panel-access')
	const switcher = root.querySelector('.mcp-view-switch')
	const viewButtons = [...switcher.querySelectorAll('button[data-view]')]
	const usersTable = document.getElementById('mcp-users')
	const dialog = document.getElementById('mcp-user-dialog')
	const dialogTitle = document.getElementById('mcp-user-dialog-title')
	const dialogBody = document.getElementById('mcp-user-dialog-body')
	const dialogClose = document.getElementById('mcp-user-dialog-close')
	const VIEWS = ['users', 'groups', 'matrix']
	let view = 'users'

	/** @return {URLSearchParams|null} the parameters of #access?…, or null on another tab */
	function accessParams() {
		const [tab, query = ''] = window.location.hash.slice(1).split('?')
		return tab === 'access' ? new URLSearchParams(query) : null
	}

	/**
	 * Writes one parameter of the Access tab address, or removes it.
	 *
	 * @param {string} name parameter name
	 * @param {string|null} value new value, null to remove it
	 * @param {boolean} replace correct the current history entry instead of adding one
	 */
	function writeParam(name, value, replace) {
		const params = accessParams() ?? new URLSearchParams()
		if (value === null) {
			params.delete(name)
		} else {
			params.set(name, value)
		}
		const next = '#access' + (params.toString() ? '?' + params.toString() : '')
		if (next === window.location.hash) {
			return
		}
		if (replace) {
			window.history.replaceState(window.history.state, '', window.location.pathname + window.location.search + next)
		} else {
			window.location.hash = next
		}
	}

	/** @param {string} next 'users', 'groups' or 'matrix' */
	function applyView(next) {
		view = VIEWS.includes(next) ? next : 'users'
		for (const candidate of VIEWS) {
			block.classList.toggle('mcp-view-' + candidate, view === candidate)
		}
		for (const button of viewButtons) {
			button.setAttribute('aria-pressed', button.dataset.view === view ? 'true' : 'false')
		}
	}

	for (const button of viewButtons) {
		button.addEventListener('click', () => {
			applyView(button.dataset.view)
			writeParam('view', view === 'users' ? null : view, false)
		})
	}

	/**
	 * A short text of what the user may do, for the Users view.
	 *
	 * @param {object} user row from the API
	 * @param {Object<string, string[]>} catalog operations per module shown on this server
	 * @return {string}
	 */
	function accessSummary(user, catalog) {
		const reason = rowUnavailableReason(user)
		if (reason !== null) {
			return reason
		}
		const labels = moduleLabels()
		const apps = Object.keys(catalog).filter((module) => module !== 'logs' && user.appsEnabled[module])
		const reads = apps.filter((module) => user.grants[module].read === true)
		const writes = apps.filter((module) => catalog[module].some((operation) => operation !== 'read' && user.grants[module][operation] === true))
		const parts = [t('mcp', 'Reads in {count} of {total} apps', { count: reads.length, total: apps.length })]
		parts.push(writes.length === 0
			? t('mcp', 'no changes allowed')
			: t('mcp', 'changes in {apps}', { apps: writes.map((module) => labels[module] || module).join(', ') }, undefined, { escape: false }))
		if (catalog.logs && user.appsEnabled.logs && user.grants.logs.read) {
			parts.push(t('mcp', 'reads the server log'))
		}
		return parts.join(' · ')
	}

	/**
	 * A yes/no cell that reads the same with or without colour.
	 *
	 * @param {boolean} on value
	 * @param {string} yes text when true
	 * @param {string} no text when false
	 * @return {HTMLTableCellElement}
	 */
	function yesNo(on, yes, no) {
		const mark = el('span', { className: 'mcp-yesno-mark', textContent: on ? '✓' : '✗' })
		mark.setAttribute('aria-hidden', 'true')
		return el('td', { className: on ? 'mcp-yes' : 'mcp-no' }, [mark, ' ', on ? yes : no])
	}

	/**
	 * "Can connect" with where it comes from, so a user allowed or denied by a group reads differently from one
	 * with an individual setting.
	 *
	 * @param {object} user row from the API
	 * @return {HTMLTableCellElement}
	 */
	function canConnectCell(user) {
		const cell = yesNo(user.eligible, t('mcp', 'Yes'), t('mcp', 'No'))
		const decision = decisionOf(user, null, null)
		const via = decision.source === 'group'
			? (decision.deny.length > 0 ? decision.deny : decision.allow).join(', ')
			: (decision.source === 'override' ? t('mcp', 'individual') : t('mcp', 'default'))
		cell.append(el('span', { className: 'mcp-uid', textContent: via }))
		cell.title = sourceText(decision)
		return cell
	}

	/** Renders the Users view from the page js/admin-grants.js loaded. */
	function renderUsers(ok) {
		const data = shared.data()
		const body = usersTable.tBodies[0]
		usersTable.setAttribute('aria-busy', 'false')
		if (!ok || !data) {
			const retry = el('button', { type: 'button', textContent: t('mcp', 'Retry'), onclick: () => shared.reload() })
			body.replaceChildren(el('tr', {}, [el('td', { colSpan: 5, className: 'mcp-empty mcp-load-error' }, [t('mcp', 'Could not load the users.'), ' ', retry])]))
			return
		}
		if (data.users.length === 0) {
			body.replaceChildren(el('tr', {}, [el('td', { colSpan: 5, className: 'mcp-empty', textContent: t('mcp', 'No users found.') })]))
			return
		}
		body.replaceChildren(...data.users.map((user) => {
			const name = el('th', { scope: 'row' }, [
				el('span', { className: 'mcp-name', textContent: user.displayName }),
				el('span', { className: 'mcp-uid', textContent: user.uid }),
			])
			if (!user.enabled) {
				name.append(el('span', { className: 'mcp-badge', textContent: t('mcp', 'disabled') }))
			}
			const edit = el('button', { type: 'button', className: 'mcp-edit-user', textContent: t('mcp', 'Edit') })
			edit.setAttribute('aria-label', t('mcp', 'Edit the permissions of {user}', { user: user.displayName }, undefined, { escape: false }))
			edit.addEventListener('click', () => open(user.uid, edit))
			const activation = user.eligible
				? yesNo(user.connected, t('mcp', 'On'), t('mcp', 'Off'))
				: el('td', { className: 'mcp-na', textContent: '—', title: t('mcp', 'Only users who can connect can turn it on.') })
			const row = el('tr', { className: user.enabled ? '' : 'mcp-disabled-user' }, [
				name,
				canConnectCell(user),
				activation,
				el('td', { className: 'mcp-summary', textContent: accessSummary(user, data.catalog) }),
				el('td', { className: 'mcp-actions' }, [edit]),
			])
			row.dataset.uid = user.uid
			return row
		}))
	}

	root.addEventListener('mcp:users-loaded', (event) => renderUsers(event.detail.ok))

	/** The user the dialog shows, the control that opened it, and whether anything was saved. */
	let current = null
	let opener = null
	let changed = false

	/** @return {Promise<object>} the matrix page once it is loaded, for the catalog of permissions */
	function pageData() {
		const data = shared.data()
		if (data) {
			return Promise.resolve(data)
		}
		return new Promise((resolve) => root.addEventListener('mcp:users-loaded', () => resolve(shared.data()), { once: true }))
	}

	/**
	 * Opens the detail panel of one user.
	 *
	 * @param {string} uid user id
	 * @param {HTMLElement|null} from control to give the focus back to on close
	 */
	async function open(uid, from) {
		opener = from
		changed = false
		dialogTitle.textContent = uid
		dialogBody.replaceChildren(el('p', { className: 'mcp-empty', textContent: t('mcp', 'Loading…') }))
		if (!dialog.open) {
			dialog.showModal()
		}
		dialogTitle.focus()
		writeParam('user', uid, true)
		try {
			const [detail, data] = await Promise.all([api('GET', '/api/effective/' + encodeURIComponent(uid)), pageData()])
			current = detail
			renderDetail(data.catalog, null)
			dialogTitle.focus()
		} catch (e) {
			current = null
			dialogBody.replaceChildren(el('p', { className: 'mcp-empty mcp-load-error', textContent: t('mcp', 'Could not load this user.') }))
		}
	}

	/** One sentence on what still stops the user, or that nothing does. */
	function readiness(access) {
		if (!access.serviceEnabled) {
			return { ok: false, text: t('mcp', 'The MCP service is off for the whole server.') }
		}
		if (!access.enabled) {
			return { ok: false, text: t('mcp', 'The account is disabled, so the user has no MCP access.') }
		}
		if (!access.eligible.result) {
			return { ok: false, text: t('mcp', 'No MCP access yet: allow “Can connect” below, or in one of the user’s groups.') }
		}
		if (!access.connected) {
			return { ok: false, text: t('mcp', 'May connect, but has not turned the connection on in their personal settings yet.') }
		}
		return { ok: true, text: t('mcp', 'Ready: this user can use MCP clients with the permissions below.') }
	}

	/**
	 * What the user would get with no individual setting: the groups (a deny wins), then the default.
	 *
	 * @param {{allow: string[], deny: string[]}} decision the groups that allow or deny
	 * @param {boolean} byDefault the default of the permission
	 * @return {string}
	 */
	function inheritedText(decision, byDefault) {
		if (decision.deny.length > 0) {
			return t('mcp', 'Inherit gives: denied by {groups}.', { groups: decision.deny.join(', ') }, undefined, { escape: false })
		}
		if (decision.allow.length > 0) {
			return t('mcp', 'Inherit gives: allowed by {groups}.', { groups: decision.allow.join(', ') }, undefined, { escape: false })
		}
		return byDefault ? t('mcp', 'Inherit gives: allowed by default.') : t('mcp', 'Inherit gives: denied by default.')
	}

	/**
	 * One permission of the panel: its name and help, the result, Allow / Deny / Inherit, and why.
	 *
	 * @param {string} id element id prefix, stable across re-renders so the focus can come back to it
	 * @param {string} label visible name
	 * @param {string} hint help next to the name
	 * @param {{result: boolean, source: string, override: boolean|null, allow: string[], deny: string[]}} decision see EffectiveAccess
	 * @param {boolean} byDefault the default of the permission
	 * @param {string|null} reason why it cannot be changed, null when it can
	 * @param {object} body request body without its state
	 * @return {HTMLElement}
	 */
	function control(id, label, hint, decision, byDefault, reason, body) {
		const status = el('span', { className: 'mcp-status', id: id + '-status' })
		status.setAttribute('aria-live', 'polite')
		const selected = decision.override === null ? 'inherit' : (decision.override ? 'allow' : 'deny')
		const group = el('div', { className: 'mcp-tri', role: 'radiogroup' })
		group.setAttribute('aria-label', label)
		const why = el('span', { className: 'mcp-detail-why', id: id + '-why' })
		const parts = [sourceText(decision)]
		if (decision.override !== null) {
			parts.push(inheritedText(decision, byDefault))
		}
		if (reason !== null) {
			parts.unshift(reason)
		}
		why.textContent = parts.join(' ')
		for (const [value, text] of [['allow', t('mcp', 'Allow')], ['deny', t('mcp', 'Deny')], ['inherit', t('mcp', 'Inherit')]]) {
			const radio = el('input', { type: 'radio', name: id, value, id: id + '-' + value, checked: value === selected, disabled: reason !== null })
			radio.setAttribute('aria-describedby', why.id)
			radio.addEventListener('change', () => save(id, value, status, body))
			group.append(el('label', { htmlFor: radio.id }, [radio, text]))
		}
		const mark = el('span', { className: 'mcp-yesno-mark', textContent: decision.result ? '✓' : '✗' })
		mark.setAttribute('aria-hidden', 'true')
		const result = el('span', { className: 'mcp-result ' + (decision.result ? 'mcp-result-yes' : 'mcp-result-no') }, [mark, decision.result ? t('mcp', 'Allowed') : t('mcp', 'Denied')])
		const name = el('span', { className: 'mcp-detail-name', textContent: label })
		if (hint) {
			name.title = hint
		}
		return el('div', { className: 'mcp-detail-row' + (reason !== null ? ' mcp-detail-op-unavailable' : '') }, [
			el('span', {}, [name, el('span', { className: 'mcp-detail-hint', textContent: hint ? ' — ' + hint : '' })]),
			result,
			el('span', {}, [group, ' ', status]),
			why,
		])
	}

	/**
	 * Saves one change of the panel, reloads the user's access and redraws it, keeping the focus and the
	 * "Saved" next to the control.
	 *
	 * @param {string} id element id prefix of the control
	 * @param {string} value "allow", "deny" or "inherit"
	 * @param {HTMLElement} status its status
	 * @param {object} body request body without its state
	 */
	async function save(id, value, status, body) {
		for (const radio of dialogBody.querySelectorAll('input[name="' + id + '"]')) {
			radio.disabled = true
		}
		status.textContent = t('mcp', 'Saving…')
		try {
			await api('PUT', '/api/grants/' + encodeURIComponent(current.uid), { ...body, state: value })
			current = await api('GET', '/api/effective/' + encodeURIComponent(current.uid))
			changed = true
			renderDetail(shared.data().catalog, { id: id + '-' + value, status: id + '-status', text: t('mcp', 'Saved'), ok: true })
		} catch (e) {
			status.textContent = t('mcp', 'Not saved')
			shared.flash(status, false)
			notifyError(t('mcp', 'Could not save the permission for {user}.', { user: current.displayName }))
			renderDetail(shared.data().catalog, { id: null, status: id + '-status', text: t('mcp', 'Not saved'), ok: false })
		}
	}

	/**
	 * Draws the panel from `current` (the answer of /api/effective/{uid}).
	 *
	 * @param {Object<string, string[]>} catalog operations per module shown on this server
	 * @param {{id: string|null, status: string, text: string, ok: boolean}|null} saved the control whose save just finished
	 */
	function renderDetail(catalog, saved) {
		const access = current
		const labels = moduleLabels()
		const operations = operationLabels()
		dialogTitle.textContent = access.displayName
		const state = readiness(access)
		const byKey = new Map(access.permissions.map((row) => [row.module + '.' + row.operation, row]))
		const available = {}
		for (const row of access.permissions) {
			available[row.module] = row.available
		}
		// The shape unavailableReason reads, so the panel and the matrix give the same reasons.
		const user = { enabled: access.enabled, eligible: access.eligible.result, appsEnabled: available }
		const groupLinks = access.groups.length === 0 ? [t('mcp', 'none')] : access.groups.flatMap((group, index) => {
			const link = el('a', { href: '#access?view=groups', textContent: group.displayName })
			link.addEventListener('click', () => {
				const search = document.getElementById('mcp-gg-search')
				if (search) {
					search.value = group.displayName
					search.dispatchEvent(new Event('input'))
				}
				dialog.close()
			})
			return index === 0 ? [link] : [', ', link]
		})
		const connectionsLink = el('a', { href: '#mcp-connections', textContent: t('mcp', 'Show them in Clients and connections') })
		connectionsLink.addEventListener('click', () => {
			const search = document.getElementById('mcp-connections-search')
			if (search) {
				search.value = access.uid
				search.dispatchEvent(new Event('input'))
			}
			dialog.close()
		})
		const connections = access.connections >= 100 ? t('mcp', '100 or more') : String(access.connections)
		const facts = el('dl', { className: 'mcp-detail-facts' }, [
			el('dt', { textContent: t('mcp', 'User ID') }), el('dd', {}, [el('code', { textContent: access.uid })]),
			el('dt', { textContent: t('mcp', 'Account') }), el('dd', { textContent: access.enabled ? t('mcp', 'enabled') : t('mcp', 'disabled') }),
			el('dt', { textContent: t('mcp', 'Groups') }), el('dd', {}, groupLinks),
			el('dt', { textContent: t('mcp', 'Personal activation') }),
			el('dd', { textContent: access.connected ? t('mcp', 'On') : t('mcp', 'Off — the user turns it on in their personal settings.') }),
			el('dt', { textContent: t('mcp', 'Active connections') }),
			el('dd', {}, access.connections > 0 ? [connections, ' · ', connectionsLink] : [connections]),
		])
		const sections = [
			el('p', { className: 'mcp-detail-state ' + (state.ok ? 'mcp-detail-ok' : 'mcp-detail-warn'), textContent: state.text }),
			facts,
			el('p', { className: 'mcp-detail-hint', textContent: t('mcp', 'Allow and Deny are individual settings and win over every group; Inherit lets the user’s groups decide, then the default.') }),
			el('fieldset', { className: 'mcp-detail-group' }, [
				el('legend', { textContent: t('mcp', 'Connection') }),
				control('mcp-ud-eligible', t('mcp', 'Can connect'), t('mcp', 'The administrator allows this user to connect an MCP client'),
					access.eligible, false, unavailableReason(user, null), {}),
			]),
		]
		for (const [module, ops] of Object.entries(catalog)) {
			const reason = unavailableReason(user, module)
			const children = [el('legend', { textContent: labels[module] || module })]
			if (module === 'logs') {
				const settings = el('a', { href: '#mcp-block-logs', textContent: t('mcp', 'Open the Server log settings') })
				settings.addEventListener('click', () => dialog.close())
				children.push(el('p', { className: 'mcp-detail-hint' }, [
					t('mcp', 'The server log shows file names, addresses and accounts of every user. Besides this permission, the user must be an administrator or a member of a group chosen in Advanced → Server log, and the log must be a file.'),
					' ', settings,
				]))
			}
			if (reason !== null) {
				children.push(el('p', { className: 'mcp-detail-unavailable', textContent: reason }))
			}
			for (const operation of ops) {
				const decision = byKey.get(module + '.' + operation)
				if (!decision) {
					continue
				}
				children.push(control('mcp-ud-' + module + '-' + operation, operations[operation] || operation, operationHint(module, operation),
					decision, operation === 'read' && module !== 'logs', reason === null ? null : t('mcp', 'Saved setting kept.'), { module, operation }))
			}
			const fieldset = el('fieldset', { className: 'mcp-detail-group' }, children)
			fieldset.dataset.module = module
			sections.push(fieldset)
		}
		dialogBody.replaceChildren(...sections)
		if (saved) {
			const status = document.getElementById(saved.status)
			if (status) {
				status.textContent = saved.text
				shared.flash(status, saved.ok)
			}
			if (saved.id) {
				document.getElementById(saved.id)?.focus()
			}
		}
	}

	/** Lets the matrix open the panel from a user's name. */
	window.McpAdminUsers = { open }

	dialogClose.addEventListener('click', () => dialog.close())
	dialog.addEventListener('close', async () => {
		const uid = current?.uid ?? opener?.closest('tr')?.dataset.uid
		current = null
		writeParam('user', null, true)
		const from = opener
		opener = null
		if (changed) {
			await shared.reload()
		}
		if (from && document.contains(from)) {
			from.focus()
			return
		}
		// The list was redrawn, or the panel came from the address: the focus goes to that user's Edit button.
		const row = uid ? [...usersTable.tBodies[0].rows].find((candidate) => candidate.dataset.uid === uid) : null
		row?.querySelector('.mcp-edit-user')?.focus()
	})

	/** Applies the view and opens the user named in the address. */
	function readHash() {
		const params = accessParams()
		if (params === null) {
			return
		}
		applyView(params.get('view') || 'users')
		const uid = params.get('user')
		if (uid && (!dialog.open || current === null || current.uid !== uid)) {
			open(uid, null)
		}
	}

	switcher.hidden = false
	applyView('users')
	window.addEventListener('hashchange', readHash)
	readHash()
})()
