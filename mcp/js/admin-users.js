/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Users view and per-user detail panel of the "Access and permissions" tab.
 *
 * The Users view is the default: one readable row per user (name, user ID, "Can connect", personal
 * activation, a short access summary and Edit), built from the same page of GET /apps/mcp/api/grants that
 * js/admin-grants.js loads for the matrix, which stays one click away as "Matrix (advanced)". Edit (or the
 * address #access?user=<uid>) opens a dialog with that user's permissions grouped by app, loaded from
 * GET /apps/mcp/api/grants/{uid}; every change saves at once through PUT /apps/mcp/api/grants/{uid} and
 * shows its result next to the control. The chosen view is kept in the address (#access?view=matrix).
 * Uses window.McpAdmin from js/admin-grants.js. Plain browser JavaScript, no build step.
 */
(function () {
	'use strict'

	const root = document.getElementById('mcp-admin')
	const shared = window.McpAdmin
	if (!root || !shared) {
		return
	}
	const { api, el, notifyError, moduleLabels, operationLabels, operationHint, unavailableReason, rowUnavailableReason } = shared

	const block = document.getElementById('mcp-block-matrix')
	const switcher = root.querySelector('.mcp-view-switch')
	const viewButtons = [document.getElementById('mcp-view-users'), document.getElementById('mcp-view-matrix')]
	const usersTable = document.getElementById('mcp-users')
	const dialog = document.getElementById('mcp-user-dialog')
	const dialogTitle = document.getElementById('mcp-user-dialog-title')
	const dialogBody = document.getElementById('mcp-user-dialog-body')
	const dialogClose = document.getElementById('mcp-user-dialog-close')
	const VIEWS = ['users', 'matrix']
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

	/** @param {string} next 'users' or 'matrix' */
	function applyView(next) {
		view = VIEWS.includes(next) ? next : 'users'
		block.classList.toggle('mcp-view-users', view === 'users')
		block.classList.toggle('mcp-view-matrix', view === 'matrix')
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
				yesNo(user.eligible, t('mcp', 'Yes'), t('mcp', 'No')),
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
			const [detail, data] = await Promise.all([api('GET', '/api/grants/' + encodeURIComponent(uid)), pageData()])
			current = detail
			renderDetail(data.catalog, null)
			dialogTitle.focus()
		} catch (e) {
			current = null
			dialogBody.replaceChildren(el('p', { className: 'mcp-empty mcp-load-error', textContent: t('mcp', 'Could not load this user.') }))
		}
	}

	/** One sentence on what still stops the user, or that nothing does. */
	function readiness(user) {
		if (!user.enabled) {
			return { ok: false, text: t('mcp', 'The account is disabled, so the user has no MCP access.') }
		}
		if (!user.eligible) {
			return { ok: false, text: t('mcp', 'No MCP access yet: turn on “Can connect” below.') }
		}
		if (!user.connected) {
			return { ok: false, text: t('mcp', 'May connect, but has not turned the connection on in their personal settings yet.') }
		}
		return { ok: true, text: t('mcp', 'Ready: this user can use MCP clients with the permissions below.') }
	}

	/**
	 * One checkbox of the panel, with its label, help and the status of its last save.
	 *
	 * @param {string} id element id, stable across re-renders so the focus can come back to it
	 * @param {string} label visible label
	 * @param {string} hint help under the label
	 * @param {boolean} checked saved value
	 * @param {string|null} reason why it cannot be changed, null when it can
	 * @param {object} body request body; the null field receives the new value
	 * @return {HTMLElement}
	 */
	function control(id, label, hint, checked, reason, body) {
		const box = el('input', { type: 'checkbox', id, checked, disabled: reason !== null })
		const status = el('span', { className: 'mcp-status', id: id + '-status' })
		status.setAttribute('aria-live', 'polite')
		const help = el('span', { className: 'mcp-detail-hint', id: id + '-hint', textContent: reason !== null ? reason : hint })
		box.setAttribute('aria-describedby', help.id)
		box.addEventListener('change', () => save(box, status, body))
		return el('div', { className: 'mcp-detail-op' + (reason !== null ? ' mcp-detail-op-unavailable' : '') }, [
			box, el('label', { htmlFor: id, textContent: label }), status, help,
		])
	}

	/**
	 * Saves one change of the panel and redraws it, keeping the focus and the "Saved" next to the control.
	 *
	 * @param {HTMLInputElement} box the checkbox
	 * @param {HTMLElement} status its status
	 * @param {object} body request body template
	 */
	async function save(box, status, body) {
		const value = box.checked
		const payload = Object.fromEntries(Object.entries(body).map(([key, v]) => [key, v === null ? value : v]))
		box.disabled = true
		status.textContent = t('mcp', 'Saving…')
		try {
			const updated = await api('PUT', '/api/grants/' + encodeURIComponent(current.uid), payload)
			current = { ...current, ...updated }
			changed = true
			renderDetail(shared.data().catalog, { id: box.id, text: t('mcp', 'Saved'), ok: true })
		} catch (e) {
			box.checked = !value
			box.disabled = false
			status.textContent = t('mcp', 'Not saved')
			shared.flash(status, false)
			notifyError(t('mcp', 'Could not save the permission for {user}.', { user: current.displayName }))
		}
	}

	/**
	 * Draws the panel from `current`.
	 *
	 * @param {Object<string, string[]>} catalog operations per module shown on this server
	 * @param {{id: string, text: string, ok: boolean}|null} saved the control whose save just finished
	 */
	function renderDetail(catalog, saved) {
		const user = current
		const labels = moduleLabels()
		const operations = operationLabels()
		dialogTitle.textContent = user.displayName
		const state = readiness(user)
		const groups = user.groups.length === 0 ? t('mcp', 'none') : user.groups.map((group) => group.displayName).join(', ')
		const connectionsLink = el('a', { href: '#mcp-connections', textContent: t('mcp', 'Show them in Clients and connections') })
		connectionsLink.addEventListener('click', () => {
			const search = document.getElementById('mcp-connections-search')
			if (search) {
				search.value = user.uid
				search.dispatchEvent(new Event('input'))
			}
			dialog.close()
		})
		const connections = user.connections >= 100 ? t('mcp', '100 or more') : String(user.connections)
		const facts = el('dl', { className: 'mcp-detail-facts' }, [
			el('dt', { textContent: t('mcp', 'User ID') }), el('dd', {}, [el('code', { textContent: user.uid })]),
			el('dt', { textContent: t('mcp', 'Account') }), el('dd', { textContent: user.enabled ? t('mcp', 'enabled') : t('mcp', 'disabled') }),
			el('dt', { textContent: t('mcp', 'Groups') }), el('dd', { textContent: groups }),
			el('dt', { textContent: t('mcp', 'Personal activation') }),
			el('dd', { textContent: user.connected ? t('mcp', 'On') : t('mcp', 'Off — the user turns it on in their personal settings.') }),
			el('dt', { textContent: t('mcp', 'Active connections') }),
			el('dd', {}, user.connections > 0 ? [connections, ' · ', connectionsLink] : [connections]),
		])
		const sections = [
			el('p', { className: 'mcp-detail-state ' + (state.ok ? 'mcp-detail-ok' : 'mcp-detail-warn'), textContent: state.text }),
			facts,
			el('fieldset', { className: 'mcp-detail-group' }, [
				el('legend', { textContent: t('mcp', 'Connection') }),
				control('mcp-ud-eligible', t('mcp', 'Can connect'), t('mcp', 'The administrator allows this user to connect an MCP client'),
					user.eligible, unavailableReason(user, null), { eligible: null }),
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
			const grid = el('div', { className: 'mcp-detail-ops' })
			for (const operation of ops) {
				grid.append(control('mcp-ud-' + module + '-' + operation, operations[operation] || operation, operationHint(module, operation),
					user.grants[module][operation], reason === null ? null : (user.grants[module][operation] ? t('mcp', 'Saved value: allowed.') : t('mcp', 'Saved value: denied.')),
					{ module, operation, granted: null }))
			}
			children.push(grid)
			const fieldset = el('fieldset', { className: 'mcp-detail-group' }, children)
			fieldset.dataset.module = module
			sections.push(fieldset)
		}
		dialogBody.replaceChildren(...sections)
		if (saved) {
			const box = document.getElementById(saved.id)
			const status = document.getElementById(saved.id + '-status')
			if (status) {
				status.textContent = saved.text
				shared.flash(status, saved.ok)
			}
			box?.focus()
		}
	}

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
