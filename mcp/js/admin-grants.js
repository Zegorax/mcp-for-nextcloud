/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Admin permission matrix (users × permissions) for the MCP app.
 *
 * Loads one page of users from GET /apps/mcp/api/grants and saves each checkbox immediately through
 * PUT /apps/mcp/api/grants/{uid}, POST /apps/mcp/api/grants/bulk and PUT /apps/mcp/api/service.
 * Plain browser JavaScript, no build step; strings go through t('mcp', …) for translation.
 */
(function () {
	'use strict'

	const root = document.getElementById('mcp-admin')
	if (!root) {
		return
	}

	/** Columns the matrix always shows besides the permissions: user, "Can connect" and "Connected". */
	const FIXED_COLUMNS = 3
	const state = { search: '', group: '', filter: 'eligible', page: 1, app: '', data: null, groupsLoaded: false }
	const table = document.getElementById('mcp-matrix')
	const searchInput = document.getElementById('mcp-search')
	const groupSelect = document.getElementById('mcp-group')
	const filterSelect = document.getElementById('mcp-filter')
	const appSelect = document.getElementById('mcp-app')
	const compactBox = document.getElementById('mcp-compact')
	const countInfo = document.getElementById('mcp-count')
	const prevButtons = [document.getElementById('mcp-prev'), document.getElementById('mcp-prev-bottom')]
	const nextButtons = [document.getElementById('mcp-next'), document.getElementById('mcp-next-bottom')]
	const pageInfos = [document.getElementById('mcp-page-info'), document.getElementById('mcp-page-info-bottom')]
	/** localStorage key of the compact-rows preference; a convenience of this browser only. */
	const COMPACT_KEY = 'mcp-admin-compact'
	const serviceBox = document.getElementById('mcp-service')
	const serviceStatus = document.getElementById('mcp-service-status')

	/** @return {Object<string, string>} module labels */
	function moduleLabels() {
		return {
			files: t('mcp', 'Files'),
			notes: t('mcp', 'Notes'),
			deck: t('mcp', 'Deck'),
			calendar: t('mcp', 'Calendar'),
			contacts: t('mcp', 'Contacts'),
			tasks: t('mcp', 'Tasks'),
			talk: t('mcp', 'Talk'),
			people: t('mcp', 'People'),
			logs: t('mcp', 'Server log'),
		}
	}

	/** @return {Object<string, string>} operation labels */
	function operationLabels() {
		return {
			read: t('mcp', 'read'),
			edit: t('mcp', 'edit'),
			create: t('mcp', 'create'),
			move: t('mcp', 'move'),
			delete: t('mcp', 'delete'),
			transfer: t('mcp', 'transfer'),
			reply: t('mcp', 'reply'),
			attach: t('mcp', 'attach'),
			quote: t('mcp', 'quote'),
			share: t('mcp', 'share'),
			link: t('mcp', 'link'),
		}
	}

	/**
	 * What each operation lets the AI do, shown as the column tooltip.
	 *
	 * @param {string} module module id
	 * @param {string} operation operation id
	 * @return {string} description
	 */
	function operationHint(module, operation) {
		if (module === 'talk' && operation === 'create') {
			return t('mcp', 'Open new conversations, which invites people')
		}
		if (module === 'files' && operation === 'create') {
			return t('mcp', 'Create folders, copies and new files')
		}
		if (module === 'logs' && operation === 'read') {
			return t('mcp', 'Read and analyze the server log; starts off')
		}
		if (module === 'calendar' && operation === 'transfer') {
			return t('mcp', 'Move an event to another person’s calendar')
		}
		const hints = {
			read: t('mcp', 'List, search and read'),
			edit: t('mcp', 'Change existing items'),
			create: t('mcp', 'Create new items'),
			move: t('mcp', 'Move or rename items'),
			delete: t('mcp', 'Delete items'),
			restore: t('mcp', 'Restore a file to a stored version'),
			reply: t('mcp', 'Send messages and replies'),
			attach: t('mcp', 'Share a file into a conversation'),
			quote: t('mcp', 'Quote part of a file in a message'),
			share: t('mcp', 'Share own files with people and groups'),
			link: t('mcp', 'Create public links to own files'),
		}
		return hints[operation] || ''
	}

	/**
	 * @param {string} method HTTP method
	 * @param {string} path path below /apps/mcp
	 * @param {object} [body] JSON body
	 * @return {Promise<object>} decoded JSON response
	 */
	async function api(method, path, body) {
		const response = await fetch(OC.generateUrl('/apps/mcp' + path), {
			method,
			headers: { 'Content-Type': 'application/json', Accept: 'application/json', requesttoken: OC.requestToken },
			body: body === undefined ? undefined : JSON.stringify(body),
		})
		if (!response.ok) {
			throw new Error(String(response.status))
		}
		return response.json()
	}

	/** @param {string} message error shown to the admin */
	function notifyError(message) {
		// The old notification global is gone since Nextcloud 34 and OCP.Toast is deprecated, so without it the browser's own dialog
		// still shows the message instead of losing it.
		if (window.OCP && OCP.Toast) {
			OCP.Toast.error(message)
		} else {
			window.alert(message)
		}
	}

	/**
	 * @param {HTMLElement} cell cell that shows the save result
	 * @param {boolean} ok whether the save worked
	 */
	function flash(cell, ok) {
		cell.classList.remove('mcp-ok', 'mcp-error')
		void cell.offsetWidth
		cell.classList.add(ok ? 'mcp-ok' : 'mcp-error')
	}

	/**
	 * @param {string} tag element name
	 * @param {object} [props] properties to assign
	 * @param {Array<Node|string>} [children] child nodes or text
	 * @return {HTMLElement}
	 */
	function el(tag, props, children) {
		const node = document.createElement(tag)
		Object.assign(node, props || {})
		for (const child of children || []) {
			node.append(child)
		}
		return node
	}

	/** Reads the current page from the server and re-renders the table; a failure is shown in the table with a retry. */
	async function load() {
		table.setAttribute('aria-busy', 'true')
		table.classList.add('mcp-loading')
		const query = new URLSearchParams({ search: state.search, group: state.group, filter: state.filter, page: String(state.page) })
		try {
			state.data = await api('GET', '/api/grants?' + query.toString())
			render()
		} catch (e) {
			renderError()
			notifyError(t('mcp', 'Could not load the users.'))
		} finally {
			table.setAttribute('aria-busy', 'false')
			table.classList.remove('mcp-loading')
		}
	}

	/** Replaces the body with an error row and a retry button, keeping the last header. */
	function renderError() {
		const retry = el('button', { type: 'button', textContent: t('mcp', 'Retry'), onclick: load })
		const cell = el('td', { colSpan: table.tHead.rows[1] ? table.tHead.rows[1].cells.length + FIXED_COLUMNS : 1, className: 'mcp-empty mcp-load-error' },
			[t('mcp', 'Could not load the users.'), ' ', retry])
		table.tBodies[0].replaceChildren(el('tr', {}, [cell]))
		applyAppFilter()
	}

	/** Fills the group filter once, keeping the current selection. */
	function renderGroups() {
		if (state.groupsLoaded) {
			return
		}
		for (const group of state.data.groups) {
			groupSelect.append(el('option', { value: group.id, textContent: group.displayName }))
		}
		state.groupsLoaded = true
	}

	/** Populate app choices from the actual catalog, preserving a valid selection. */
	function renderApps() {
		const labels = moduleLabels()
		const modules = Object.keys(state.data.catalog)
		if (!modules.includes(state.app)) {
			const stale = state.app !== ''
			state.app = ''
			if (stale) {
				// An address naming an unknown app is corrected in place, without a new history entry.
				writeAppToHash(true)
			}
		}
		appSelect.replaceChildren(el('option', { value: '', textContent: t('mcp', 'All apps') }),
			...modules.map((module) => el('option', { value: module, textContent: labels[module] || module })))
		appSelect.value = state.app
		appSelect.disabled = false
	}

	/** Filter only presentation; user queries and permission payloads stay unchanged. */
	function applyAppFilter() {
		for (const cell of table.querySelectorAll('[data-module]')) {
			cell.hidden = state.app !== '' && cell.dataset.module !== state.app
			if (cell.hidden) {
				for (const menu of cell.querySelectorAll('details[open]')) {
					menu.open = false
				}
			}
		}
		const permissions = visiblePermissionColumns()
		for (const cell of table.querySelectorAll('td.mcp-empty')) {
			cell.colSpan = FIXED_COLUMNS + permissions
		}
		for (const cell of table.querySelectorAll('td.mcp-row-unavailable')) {
			cell.colSpan = permissions
		}
		stickSecondHeaderRow()
	}

	/** Read the Access tab's app parameter on reload and history navigation. */
	function readAppFromHash() {
		const [tab, query = ''] = window.location.hash.slice(1).split('?')
		if (tab !== 'access') {
			return
		}
		state.app = new URLSearchParams(query).get('app') || ''
		if (state.data) {
			renderApps()
			applyAppFilter()
		}
	}

	/**
	 * Writes the chosen app into the address (#access?app=<module>, no parameter for all apps).
	 *
	 * @param {boolean} replace correct the current entry instead of adding a history entry
	 */
	function writeAppToHash(replace) {
		const [tab, query = ''] = window.location.hash.slice(1).split('?')
		if (tab !== 'access') {
			return
		}
		const params = new URLSearchParams(query)
		if (state.app === '') {
			params.delete('app')
		} else {
			params.set('app', state.app)
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

	appSelect.addEventListener('change', () => {
		state.app = appSelect.value
		applyAppFilter()
		writeAppToHash(false)
	})
	window.addEventListener('hashchange', readAppFromHash)
	readAppFromHash()

	/**
	 * Header menu ("All") that allows or denies permissions for every user on the page.
	 *
	 * @param {string|null} module module id, null for eligibility
	 * @param {Array<{operations: string[], label: string}>} entries one line per permission (or group of them)
	 * @param {string} title accessible name of the menu
	 * @return {HTMLElement} a native <details> menu
	 */
	function bulkMenu(module, entries, title) {
		const list = el('div', { className: 'mcp-bulk-list' })
		for (const entry of entries) {
			const make = (granted, text) => el('button', {
				type: 'button',
				className: 'mcp-bulk',
				textContent: text,
				onclick: (event) => {
					event.target.closest('details').open = false
					bulk(module, entry.operations, granted, entry.label)
				},
			})
			list.append(el('div', { className: 'mcp-bulk-line' }, [
				el('span', { className: 'mcp-bulk-name', textContent: entry.label }),
				make(true, t('mcp', 'Allow')),
				make(false, t('mcp', 'Deny')),
			]))
		}
		const summary = el('summary', { className: 'mcp-bulk-summary', textContent: t('mcp', 'All') })
		summary.title = title
		summary.setAttribute('aria-label', title)
		return el('details', { className: 'mcp-bulk-menu' }, [summary, list])
	}

	/** Makes the second header row stick right below the first one, whose height depends on the density. */
	function stickSecondHeaderRow() {
		const [top, sub] = table.tHead.rows
		if (!top || !sub) {
			return
		}
		const offset = top.getBoundingClientRect().height
		for (const cell of sub.cells) {
			cell.style.top = offset + 'px'
		}
	}

	/** The first header row currently observed, so a re-created row (every render) is observed again. */
	let observedHeaderRow = null
	let headerObserver = null

	/**
	 * Re-measures the first header row whenever it resizes. The Access panel starts hidden, so the
	 * render there measures zero; showing the tab (or changing density or font size) resizes the row
	 * and the observer re-sticks the second row below it. A browser without ResizeObserver keeps the
	 * render-time measure, which is correct whenever the panel is visible.
	 */
	function observeHeaderRow() {
		if (!window.ResizeObserver) {
			return
		}
		const top = table.tHead?.rows?.[0]
		if (!top || top === observedHeaderRow) {
			return
		}
		if (!headerObserver) {
			headerObserver = new window.ResizeObserver(stickSecondHeaderRow)
		}
		headerObserver.disconnect()
		observedHeaderRow = top
		headerObserver.observe(top)
	}

	/** Renders header and rows from state.data. */
	function render() {
		const data = state.data
		renderGroups()
		renderApps()
		const modules = moduleLabels()
		const operations = operationLabels()

		const top = el('tr')
		const sub = el('tr')
		top.append(el('th', { rowSpan: 2, className: 'mcp-user-col', scope: 'col', textContent: t('mcp', 'User') }))
		const canConnect = t('mcp', 'Can connect')
		top.append(el('th', { rowSpan: 2, scope: 'col', title: t('mcp', 'The administrator allows this user to connect an MCP client') }, [
			canConnect, ' ', bulkMenu(null, [{ operations: ['eligible'], label: canConnect }], t('mcp', 'Change “{permission}” for every user on this page', { permission: canConnect })),
		]))
		Object.entries(data.catalog).forEach(([module, ops], index) => {
			const band = 'mcp-band-' + (index % 2)
			const name = modules[module] || module
			const entries = ops.map((operation) => ({ operations: [operation], label: name + ': ' + (operations[operation] || operation) }))
			entries.push({ operations: ops, label: t('mcp', '{module}: every permission', { module: name }) })
			const header = el('th', { colSpan: ops.length, scope: 'colgroup', className: 'mcp-module ' + band }, [
				name, ' ', bulkMenu(module, entries, t('mcp', 'Change {module} permissions for every user on this page', { module: name })),
			])
			header.dataset.module = module
			top.append(header)
			ops.forEach((operation, i) => {
				const cell = el('th', {
					scope: 'col',
					className: band + (i === 0 ? ' mcp-module' : ''),
					title: operationHint(module, operation),
					textContent: operations[operation] || operation,
				})
				cell.dataset.module = module
				sub.append(cell)
			})
		})
		top.append(el('th', { rowSpan: 2, scope: 'col', textContent: t('mcp', 'Connected') }))
		table.tHead.replaceChildren(top, sub)
		if (bulkBusy) {
			setBulkBusy(true)
		}
		stickSecondHeaderRow()
		observeHeaderRow()

		const rows = data.users.map(renderRow)
		if (rows.length === 0) {
			const columns = FIXED_COLUMNS + Object.values(data.catalog).reduce((sum, ops) => sum + ops.length, 0)
			rows.push(el('tr', {}, [el('td', { colSpan: columns, className: 'mcp-empty', textContent: t('mcp', 'No users found.') })]))
		}
		table.tBodies[0].replaceChildren(...rows)
		applyAppFilter()

		const pageText = data.total === null
			? t('mcp', 'Page {page}', { page: data.page })
			: t('mcp', 'Page {page} of {pages}', { page: data.page, pages: Math.max(1, Math.ceil(data.total / data.pageSize)) })
		for (const button of prevButtons) {
			button.disabled = data.page <= 1
		}
		for (const button of nextButtons) {
			button.disabled = !data.hasMore
		}
		for (const info of pageInfos) {
			info.textContent = pageText
		}
		const from = (data.page - 1) * data.pageSize + 1
		const to = from + data.users.length - 1
		countInfo.textContent = data.users.length === 0
			? ''
			: (data.total === null
				? t('mcp', 'Users {from}–{to}', { from, to })
				: t('mcp', 'Users {from}–{to} of {total}', { from, to, total: data.total }))
	}

	/**
	 * @param {object} user row from the API
	 * @return {HTMLTableRowElement}
	 */
	function renderRow(user) {
		const row = el('tr', { className: user.enabled ? '' : 'mcp-disabled-user' })
		row.dataset.uid = user.uid
		const name = el('th', { scope: 'row', className: 'mcp-user-col' }, [
			el('span', { className: 'mcp-name', textContent: user.displayName }),
			el('span', { className: 'mcp-uid', textContent: user.uid }),
		])
		if (!user.enabled) {
			name.append(el('span', { className: 'mcp-badge', textContent: t('mcp', 'disabled') }))
		} else if (user.eligible && user.connected) {
			name.append(el('span', { className: 'mcp-badge mcp-badge-connected', textContent: t('mcp', 'connected') }))
		}
		row.append(name)
		row.append(permissionCell(user, null, user.eligible, t('mcp', 'Can connect'), { eligible: null }))
		const rowReason = rowUnavailableReason(user)
		if (rowReason !== null) {
			// One notice instead of a mark in every column; the saved permissions come back with "Can connect".
			const notice = el('td', { className: 'mcp-cell mcp-row-unavailable', colSpan: visiblePermissionColumns(), textContent: rowReason })
			notice.title = user.enabled
				? t('mcp', 'The saved permissions are kept and shown again when “Can connect” is on.')
				: t('mcp', 'The saved permissions are kept and shown again when the account is enabled and “Can connect” is on.')
			row.append(notice)
			row.append(el('td', { className: 'mcp-connected', textContent: user.connected ? t('mcp', 'yes') : t('mcp', 'no') }))
			return row
		}
		Object.entries(state.data.catalog).forEach(([module, ops], index) => {
			ops.forEach((operation, i) => {
				const cell = permissionCell(user, module, user.grants[module][operation], module + ':' + operation, { module, operation, granted: null })
				cell.dataset.module = module
				cell.hidden = state.app !== '' && state.app !== module
				cell.classList.add('mcp-band-' + (index % 2))
				if (i === 0) {
					cell.classList.add('mcp-module')
				}
				row.append(cell)
			})
		})
		row.append(el('td', { className: 'mcp-connected', textContent: user.connected ? t('mcp', 'yes') : t('mcp', 'no') }))
		return row
	}

	/**
	 * Why none of a user's permissions can be changed: a disabled account or "Can connect" off. The
	 * row then shows this one notice across the permission columns instead of a mark in each of them.
	 *
	 * @param {object} user row from the API
	 * @return {string|null} short notice, or null when the permissions are shown cell by cell
	 */
	function rowUnavailableReason(user) {
		if (!user.enabled) {
			return t('mcp', 'Account disabled: no MCP access.')
		}
		if (!user.eligible) {
			return t('mcp', 'No MCP access: turn on “Can connect”.')
		}
		return null
	}

	/** @return {number} permission columns shown with the current app filter */
	function visiblePermissionColumns() {
		return Object.entries(state.data?.catalog || {})
			.filter(([module]) => state.app === '' || module === state.app)
			.reduce((sum, [, operations]) => sum + operations.length, 0)
	}

	/**
	 * Why a user's permission cannot be changed here, or null when it can. The first reason wins:
	 * a disabled account, then "Can connect" off, then the app (or the log role) unavailable to the user.
	 *
	 * @param {object} user row from the API
	 * @param {string|null} module module id, null for "Can connect"
	 * @return {string|null} reason shown to the admin
	 */
	function unavailableReason(user, module) {
		if (!user.enabled) {
			return t('mcp', 'The account is disabled.')
		}
		if (module === null) {
			return null
		}
		if (!user.eligible) {
			return t('mcp', 'This user may not connect: “Can connect” is off.')
		}
		if (!user.appsEnabled[module]) {
			return module === 'logs'
				? t('mcp', 'Only administrators and members of the groups selected in “Server log” can read the log; the saved permission is kept.')
				: t('mcp', 'This app is unavailable for this user; the saved permission is kept.')
		}
		return null
	}

	/**
	 * The states a permission cell can show, drawn in one place: allowed and denied are a checkbox
	 * (checked or empty), unavailable is a "no entry" mark that never looks like an empty checkbox.
	 * Each state has a name for the tooltip and for screen readers, so none depends on colour.
	 */
	const PERMISSION_STATES = {
		allowed: { className: 'mcp-state-allowed', name: () => t('mcp', 'Allowed') },
		denied: { className: 'mcp-state-denied', name: () => t('mcp', 'Denied') },
		unavailable: { className: 'mcp-state-unavailable', name: () => t('mcp', 'Unavailable') },
	}

	/**
	 * Paints one state on a cell: its class, tooltip and the name read by screen readers.
	 *
	 * @param {HTMLTableCellElement} cell the cell
	 * @param {string} key key of PERMISSION_STATES
	 * @param {string} [detail] extra sentence (the reason of an unavailable cell)
	 */
	function paintState(cell, key, detail) {
		for (const other of Object.values(PERMISSION_STATES)) {
			cell.classList.remove(other.className)
		}
		cell.classList.add(PERMISSION_STATES[key].className)
		cell.title = detail ? PERMISSION_STATES[key].name() + ': ' + detail : PERMISSION_STATES[key].name()
	}

	/**
	 * A permission cell: a checkbox that saves at once, or the unavailable mark with its reason.
	 *
	 * @param {object} user row from the API
	 * @param {string|null} module module id, null for "Can connect"
	 * @param {boolean} checked saved value
	 * @param {string} label accessible name of the permission
	 * @param {object} body request body template; the null field receives the new value
	 * @return {HTMLTableCellElement}
	 */
	function permissionCell(user, module, checked, label, body) {
		const cell = el('td', { className: 'mcp-cell' })
		const reason = unavailableReason(user, module)
		if (reason !== null) {
			const saved = checked ? t('mcp', 'Saved value: allowed.') : t('mcp', 'Saved value: denied.')
			paintState(cell, 'unavailable', reason + ' ' + saved)
			const mark = el('span', { className: 'mcp-state-mark', textContent: '⊘' })
			mark.setAttribute('aria-hidden', 'true')
			cell.append(mark, el('span', { className: 'mcp-sr-only', textContent: user.displayName + ' — ' + label + ': ' + cell.title }))
			return cell
		}
		const box = el('input', { type: 'checkbox', checked })
		box.setAttribute('aria-label', user.displayName + ' — ' + label)
		paintState(cell, checked ? 'allowed' : 'denied')
		box.addEventListener('change', async () => {
			const value = box.checked
			const payload = Object.fromEntries(Object.entries(body).map(([key, v]) => [key, v === null ? value : v]))
			const hadFocus = document.activeElement === box
			// The focus comes back to this checkbox only when the admin did not move it during the save.
			const focusStayed = () => hadFocus && (document.activeElement === null || document.activeElement === document.body || document.activeElement === box)
			box.disabled = true
			try {
				const updated = await api('PUT', '/api/grants/' + encodeURIComponent(user.uid), payload)
				const index = state.data.users.findIndex((u) => u.uid === user.uid)
				state.data.users[index] = updated
				const restore = focusStayed()
				const fresh = renderRow(updated)
				cell.parentElement.replaceWith(fresh)
				const freshCell = fresh.children[cell.cellIndex]
				flash(freshCell, true)
				if (restore) {
					freshCell.querySelector('input')?.focus()
				}
			} catch (e) {
				const restore = focusStayed()
				box.checked = !value
				box.disabled = false
				if (restore) {
					box.focus()
				}
				flash(cell, false)
				notifyError(t('mcp', 'Could not save the permission for {user}.', { user: user.displayName }))
			}
		})
		cell.append(box)
		return cell
	}

	/** Seconds the undo of a bulk change stays offered; it waits while the pointer or focus is on it. */
	const UNDO_SECONDS = 10
	const notice = document.getElementById('mcp-bulk-notice')
	const noticeText = document.getElementById('mcp-bulk-notice-text')
	const undoButton = document.getElementById('mcp-bulk-undo')
	let noticeTimer = null
	let pendingUndo = null
	/** True while a bulk change or its undo saves and the matrix reloads: only one runs at a time. */
	let bulkBusy = false

	/**
	 * Locks the "All" menus and the undo while a bulk change or an undo is saving, so an older
	 * operation can never finish on top of a newer one's notice, undo or focus.
	 *
	 * @param {boolean} on whether an operation is running
	 */
	function setBulkBusy(on) {
		bulkBusy = on
		const reason = on ? t('mcp', 'Wait until the change in progress is saved.') : ''
		for (const button of root.querySelectorAll('.mcp-bulk, #mcp-bulk-undo')) {
			button.disabled = on
			button.setAttribute('aria-disabled', on ? 'true' : 'false')
			button.title = reason
		}
		// The undo window only runs while its button can be used: it stops during the lock and
		// starts again, in full, once the lock is released.
		if (on) {
			clearTimeout(noticeTimer)
		} else if (notice.classList.contains('mcp-notice-on')) {
			armNotice()
		}
	}

	/** Clears the notice and forgets the undo it offered. */
	function hideNotice() {
		clearTimeout(noticeTimer)
		pendingUndo = null
		notice.classList.remove('mcp-notice-on')
		noticeText.textContent = ''
		undoButton.hidden = true
	}

	/** Starts the countdown, unless the admin is on the notice or a bulk change is still running. */
	function armNotice() {
		clearTimeout(noticeTimer)
		if (!bulkBusy && !notice.matches(':hover') && !notice.contains(document.activeElement)) {
			noticeTimer = setTimeout(hideNotice, UNDO_SECONDS * 1000)
		}
	}

	/**
	 * Shows a message under the matrix, with an Undo button when an undo is given.
	 *
	 * @param {string} message text of the notice
	 * @param {Function|null} undo restores the previous values
	 */
	function showNotice(message, undo) {
		// A newer notice takes the single undo slot; say so instead of dropping the older undo silently.
		const replaced = pendingUndo !== null && pendingUndo !== undo
		pendingUndo = undo
		noticeText.textContent = replaced ? message + ' ' + t('mcp', 'The previous undo is no longer available.') : message
		undoButton.hidden = undo === null
		notice.classList.add('mcp-notice-on')
		armNotice()
	}

	notice.addEventListener('mouseenter', () => clearTimeout(noticeTimer))
	notice.addEventListener('focusin', () => clearTimeout(noticeTimer))
	notice.addEventListener('mouseleave', () => notice.classList.contains('mcp-notice-on') && armNotice())
	notice.addEventListener('focusout', (event) => {
		if (notice.classList.contains('mcp-notice-on') && !notice.contains(event.relatedTarget)) {
			armNotice()
		}
	})
	undoButton.addEventListener('click', () => {
		const undo = pendingUndo
		if (!undo || bulkBusy) {
			return
		}
		// The button disappears, so the focus moves to the notice text, which announces the outcome
		// and stays on screen while it has the focus.
		pendingUndo = null
		clearTimeout(noticeTimer)
		undoButton.hidden = true
		noticeText.focus({ preventScroll: true })
		undo()
	})

	/**
	 * What a bulk change would do on this page: per operation, the users whose saved value differs
	 * and that value; users with an unavailable cell are left out, users already set are counted.
	 *
	 * @param {string|null} module module id, null for eligibility
	 * @param {string[]} operationList operation ids, or ["eligible"]
	 * @param {boolean} granted new value
	 * @return {{changes: Array<{operation: string, previous: Map<string, boolean>}>, users: number, unavailable: number, unchanged: number}}
	 */
	function bulkPlan(module, operationList, granted) {
		const changes = operationList.map((operation) => ({ operation, previous: new Map() }))
		let users = 0
		let unavailable = 0
		let unchanged = 0
		for (const user of state.data.users) {
			if (unavailableReason(user, module) !== null) {
				unavailable++
				continue
			}
			let changed = false
			for (const change of changes) {
				const current = module === null ? user.eligible : user.grants[module][change.operation]
				if (current !== granted) {
					change.previous.set(user.uid, current)
					changed = true
				}
			}
			changed ? users++ : unchanged++
		}
		return { changes: changes.filter((change) => change.previous.size > 0), users, unavailable, unchanged }
	}

	/**
	 * Saves a bulk change through the bulk endpoint, one request per operation and saved value.
	 *
	 * @param {string|null} module module id, null for eligibility
	 * @param {Array<{operation: string, uids: string[], granted: boolean}>} requests requests to send in order
	 * @return {Promise<number>} how many requests were saved before a failure (all of them on success)
	 */
	async function sendBulk(module, requests) {
		let saved = 0
		try {
			for (const request of requests) {
				await api('POST', '/api/grants/bulk', { uids: request.uids, module, operation: request.operation, granted: request.granted })
				saved++
			}
		} catch (e) {
			// The caller reports how far it got.
		}
		return saved
	}

	/**
	 * Requests that put back the previous values, grouped by operation and previous value.
	 *
	 * @param {Array<{operation: string, previous: Map<string, boolean>}>} changes changes that were saved
	 * @return {Array<{operation: string, uids: string[], granted: boolean}>}
	 */
	function undoRequests(changes) {
		const requests = []
		for (const change of changes) {
			for (const value of [true, false]) {
				const uids = [...change.previous].filter(([, previous]) => previous === value).map(([uid]) => uid)
				if (uids.length > 0) {
					requests.push({ operation: change.operation, uids, granted: value })
				}
			}
		}
		return requests
	}

	/**
	 * Header "All" action: confirms exactly what changes on this page, saves it and offers an undo.
	 *
	 * @param {string|null} module module id, null for eligibility
	 * @param {string[]} operationList operation ids, or ["eligible"]; several are saved one after the other
	 * @param {boolean} granted new value
	 * @param {string} label permission name
	 */
	async function bulk(module, operationList, granted, label) {
		if (bulkBusy) {
			return
		}
		const plan = bulkPlan(module, operationList, granted)
		const vars = { permission: label }
		const lines = []
		if (plan.unavailable > 0) {
			lines.push(n('mcp', '%n user with this permission unavailable is left out.', '%n users with this permission unavailable are left out.', plan.unavailable, vars, { escape: false }))
		}
		if (plan.unchanged > 0) {
			lines.push(granted
				? n('mcp', '%n user already has it allowed.', '%n users already have it allowed.', plan.unchanged, vars, { escape: false })
				: n('mcp', '%n user already has it denied.', '%n users already have it denied.', plan.unchanged, vars, { escape: false }))
		}
		if (plan.users === 0) {
			showNotice([t('mcp', 'Nothing to change on this page for “{permission}”.', vars, undefined, { escape: false }), ...lines].join(' '), null)
			return
		}
		const question = granted
			? n('mcp', 'Allow “{permission}” for %n user on this page?', 'Allow “{permission}” for %n users on this page?', plan.users, vars, { escape: false })
			: n('mcp', 'Deny “{permission}” for %n user on this page?', 'Deny “{permission}” for %n users on this page?', plan.users, vars, { escape: false })
		if (module === null && !granted) {
			lines.push(t('mcp', 'They are signed out of their MCP clients, and undoing this does not sign them back in.'))
		}
		if (!window.confirm([question, ...lines].join('\n'))) {
			return
		}
		const requests = plan.changes.map((change) => ({ operation: change.operation, uids: [...change.previous.keys()], granted }))
		let undo = null
		setBulkBusy(true)
		try {
			const saved = await sendBulk(module, requests)
			const done = plan.changes.slice(0, saved)
			undo = done.length === 0 ? null : () => undoBulk(module, done)
			if (saved < requests.length) {
				notifyError(t('mcp', 'Could not update the users on this page.'))
				if (undo) {
					showNotice(t('mcp', 'Part of the change was saved.'), undo)
				}
			} else {
				showNotice(granted
					? n('mcp', 'Allowed “{permission}” for %n user.', 'Allowed “{permission}” for %n users.', plan.users, vars, { escape: false })
					: n('mcp', 'Denied “{permission}” for %n user.', 'Denied “{permission}” for %n users.', plan.users, vars, { escape: false }), undo)
			}
			await load()
		} finally {
			setBulkBusy(false)
		}
		if (undo !== null && pendingUndo === undo) {
			// The menu that started the change is gone after the reload; the focus goes to its undo,
			// scrolling the notice under the table into view when it is below the fold.
			undoButton.focus()
		}
	}

	/**
	 * Puts back the values a bulk change replaced; a failure part way is reported and the matrix reloaded.
	 * It restores what the bulk saw, so a change someone else made in between is overwritten (last write
	 * wins): grants have no versioning yet, which is left for 1.1.0.
	 *
	 * @param {string|null} module module id, null for eligibility
	 * @param {Array<{operation: string, previous: Map<string, boolean>}>} changes changes that were saved
	 */
	async function undoBulk(module, changes) {
		const requests = undoRequests(changes)
		setBulkBusy(true)
		try {
			const saved = await sendBulk(module, requests)
			if (saved < requests.length) {
				const failure = t('mcp', 'Could not undo the whole change. The table was reloaded with the saved values.')
				notifyError(failure)
				showNotice(failure, null)
			} else {
				showNotice(t('mcp', 'Change undone.'), null)
			}
			await load()
		} finally {
			setBulkBusy(false)
		}
	}

	serviceBox.addEventListener('change', async () => {
		const value = serviceBox.checked
		serviceBox.disabled = true
		try {
			const result = await api('PUT', '/api/service', { enabled: value })
			serviceBox.checked = result.enabled
			serviceStatus.textContent = t('mcp', 'Saved')
			flash(serviceStatus, true)
		} catch (e) {
			serviceBox.checked = !value
			serviceStatus.textContent = t('mcp', 'Not saved')
			flash(serviceStatus, false)
			notifyError(t('mcp', 'Could not change the MCP service.'))
		} finally {
			serviceBox.disabled = false
		}
	})

	let searchTimer = null
	searchInput.addEventListener('input', () => {
		clearTimeout(searchTimer)
		searchTimer = setTimeout(() => {
			state.search = searchInput.value.trim()
			state.page = 1
			load()
		}, 300)
	})
	groupSelect.addEventListener('change', () => {
		state.group = groupSelect.value
		state.page = 1
		load()
	})
	// The matrix opens on the users who can connect; "Everyone" stays one choice away.
	filterSelect.value = state.filter
	filterSelect.addEventListener('change', () => {
		state.filter = filterSelect.value
		state.page = 1
		load()
	})
	for (const button of prevButtons) {
		button.addEventListener('click', () => {
			state.page = Math.max(1, state.page - 1)
			load()
		})
	}
	for (const button of nextButtons) {
		button.addEventListener('click', () => {
			state.page += 1
			load()
		})
	}
	try {
		compactBox.checked = window.localStorage.getItem(COMPACT_KEY) === '1'
	} catch (e) {
		compactBox.checked = false
	}
	table.classList.toggle('mcp-compact', compactBox.checked)
	compactBox.addEventListener('change', () => {
		table.classList.toggle('mcp-compact', compactBox.checked)
		stickSecondHeaderRow()
		try {
			window.localStorage.setItem(COMPACT_KEY, compactBox.checked ? '1' : '0')
		} catch (e) {
			// The preference is a convenience; without storage it lasts until the page is reloaded.
		}
	})
	for (const button of root.querySelectorAll('.mcp-copy')) {
		button.addEventListener('click', () => {
			const text = document.getElementById(button.dataset.copy).textContent
			navigator.clipboard.writeText(text).then(
				() => { button.textContent = t('mcp', 'Copied') },
				() => notifyError(t('mcp', 'Could not copy the endpoint.')),
			)
		})
	}


	async function initTagsSection() {
		const container = document.getElementById('mcp-tags-list')
		const warning = document.getElementById('mcp-tags-warning')
		const status = document.getElementById('mcp-tags-status')
		const invalid = document.getElementById('mcp-tags-invalid')
		const repair = document.getElementById('mcp-tags-repair')
		// The gravest state of the app is an unreadable exclusion setting (fail-closed: every file is
		// refused). The repair block lives in the Data protection tab, so the Status block of Overview
		// also shows it and the tab carries a marker.
		const overviewAlert = document.getElementById('mcp-tags-invalid-overview')
		const tabAlert = document.getElementById('mcp-tab-protection-alert')
		if (!container || !window.McpAdminPicker) {
			return
		}

		/** @param {boolean} isInvalid shows or hides every sign of the unreadable setting */
		function setInvalidState(isInvalid) {
			if (invalid) {
				invalid.style.display = isInvalid ? 'block' : 'none'
			}
			if (overviewAlert) {
				overviewAlert.hidden = !isInvalid
			}
			if (tabAlert) {
				tabAlert.hidden = !isInvalid
			}
		}

		let allTags = []

		/**
		 * The type badge of a tag option, with its explanation in the title.
		 *
		 * @param {string} type collaborative, restricted or invisible
		 * @return {{text: string, title: string, className: string}}
		 */
		function tagBadge(type) {
			if (type === 'invisible') {
				return { text: t('mcp', 'Invisible'), title: t('mcp', 'Visible and assignable only by administrators'), className: 'mcp-tag-type-invisible' }
			}
			if (type === 'restricted') {
				return { text: t('mcp', 'Restricted'), title: t('mcp', 'Visible to users, assignable only by administrators and the groups they allow'), className: 'mcp-tag-type-restricted' }
			}
			return { text: t('mcp', 'Collaborative'), title: t('mcp', 'Users can assign and remove this tag'), className: 'mcp-tag-type-collaborative' }
		}

		/** @param {string[]} selectedIds warns when a collaborative tag, removable by editors, is selected */
		function updateWarning(selectedIds) {
			if (!warning) {
				return
			}
			const hasCollab = allTags.some((tag) => tag.type === 'collaborative' && selectedIds.includes(String(tag.id)))
			warning.style.display = hasCollab ? 'block' : 'none'
		}

		/**
		 * PUT /api/admin/tags with the same payload as before. A failure is explained here and rethrown,
		 * so the picker rolls the selection back; on success the invalid-setting block goes away.
		 *
		 * @param {string[]} tagIds the ids to save
		 */
		async function saveTags(tagIds) {
			try {
				await api('PUT', '/api/admin/tags', { tagIds })
			} catch (e) {
				// 503: the system tags could not be checked and the server kept the previous setting.
				notifyError(e.message === '503'
					? t('mcp', 'Could not check the system tags, so the exclusion tags were not changed. Try again later.')
					: t('mcp', 'Could not update exclusion tags.'))
				throw e
			}
			setInvalidState(false)
		}

		try {
			const res = await api('GET', '/api/admin/tags')
			allTags = res.tags || []

			const picker = window.McpAdminPicker.create({
				container,
				status,
				options: allTags.map((tag) => ({ id: String(tag.id), label: tag.name, badge: tagBadge(tag.type) })),
				selectedIds: (res.selected || []).map(String),
				searchLabel: t('mcp', 'Search tags'),
				listLabel: t('mcp', 'Exclusion tags'),
				emptyOptionsText: t('mcp', 'No system tags found. Create tags in Files settings.'),
				noMatchText: t('mcp', 'No match.'),
				// An empty list turns the protection off; from an invalid setting that goes from hiding
				// everything to hiding nothing, so it is never saved without the administrator saying so.
				confirmSave: (nextIds) => nextIds.length > 0
					|| window.confirm(t('mcp', 'Saving with no exclusion tags turns the protection off: every file becomes visible to the MCP tools again. Continue?')),
				save: saveTags,
				onChange: updateWarning,
				clearSavedAfterMs: 2000,
				errorStatus: '',
			})

			setInvalidState(Boolean(res.invalid))
			if (res.invalid && repair) {
				repair.addEventListener('click', async () => {
					repair.disabled = true
					try {
						await picker.commit({ force: true })
					} finally {
						repair.disabled = false
					}
				})
			}

			updateWarning(picker.getSelected())
		} catch {
			container.setAttribute('aria-busy', 'false')
			container.replaceChildren(el('p', { className: 'mcp-empty', textContent: t('mcp', 'Could not load system tags.') }))
		}
	}

	/**
	 * OAuth clients section: allowed client_id hosts, the native client switch and the custom client with its
	 * redirect URIs, through GET and PUT /apps/mcp/api/oauth-clients.
	 */
	async function initOauthClients() {
		const hostsInput = document.getElementById('mcp-oauth-hosts')
		const hostsSave = document.getElementById('mcp-oauth-hosts-save')
		const hostsStatus = document.getElementById('mcp-oauth-hosts-status')
		const nativeBox = document.getElementById('mcp-native-client')
		const nativeStatus = document.getElementById('mcp-native-client-status')
		const details = document.getElementById('mcp-native-client-details')
		const clientId = document.getElementById('mcp-native-client-id')
		const redirects = document.getElementById('mcp-native-redirects')
		const customBox = document.getElementById('mcp-custom-client')
		const customStatus = document.getElementById('mcp-custom-client-status')
		const customDetails = document.getElementById('mcp-custom-client-details')
		const customId = document.getElementById('mcp-custom-client-id')
		const customRedirects = document.getElementById('mcp-custom-redirects')
		const customSave = document.getElementById('mcp-custom-redirects-save')
		const customRedirectsStatus = document.getElementById('mcp-custom-redirects-status')

		/** @param {object} data settings returned by the API */
		function show(data) {
			hostsInput.value = data.hosts.join(', ')
			nativeBox.checked = data.nativeClientEnabled
			details.hidden = !data.nativeClientEnabled
			clientId.textContent = data.nativeClientId
			redirects.replaceChildren(...data.nativeRedirectUris.map((uri) => el('li', {}, [el('code', { textContent: uri })])))
			customBox.checked = data.customClientEnabled
			customDetails.hidden = !data.customClientEnabled
			customId.textContent = data.customClientId
			customRedirects.value = data.customRedirectUris.join('\n')
		}

		/**
		 * @param {HTMLElement} status status span next to the control
		 * @param {boolean} ok whether the save worked
		 * @param {string} [message] text shown on failure
		 */
		function report(status, ok, message) {
			status.textContent = ok ? t('mcp', 'Saved') : (message || t('mcp', 'Not saved'))
			flash(status, ok)
		}

		try {
			show(await api('GET', '/api/oauth-clients'))
		} catch (e) {
			notifyError(t('mcp', 'Could not load the OAuth client settings.'))
			return
		}

		hostsSave.addEventListener('click', async () => {
			const hosts = hostsInput.value.split(',').map((host) => host.trim().toLowerCase()).filter((host) => host !== '')
			hostsSave.disabled = true
			try {
				show(await api('PUT', '/api/oauth-clients', { hosts }))
				report(hostsStatus, true)
			} catch (e) {
				report(hostsStatus, false, t('mcp', 'Invalid host list: use lowercase host names only, without https://, port, path or *, and keep at least one.'))
			} finally {
				hostsSave.disabled = false
			}
		})

		nativeBox.addEventListener('change', async () => {
			const value = nativeBox.checked
			nativeBox.disabled = true
			try {
				show(await api('PUT', '/api/oauth-clients', { nativeClientEnabled: value }))
				report(nativeStatus, true)
			} catch (e) {
				nativeBox.checked = !value
				report(nativeStatus, false)
				notifyError(t('mcp', 'Could not change the native client.'))
			} finally {
				nativeBox.disabled = false
			}
		})

		customBox.addEventListener('change', async () => {
			const value = customBox.checked
			customBox.disabled = true
			try {
				show(await api('PUT', '/api/oauth-clients', { customClientEnabled: value }))
				report(customStatus, true)
			} catch (e) {
				customBox.checked = !value
				report(customStatus, false)
				notifyError(t('mcp', 'Could not change the self-hosted client.'))
			} finally {
				customBox.disabled = false
			}
		})

		customSave.addEventListener('click', async () => {
			const customRedirectUris = customRedirects.value.split('\n').map((uri) => uri.trim()).filter((uri) => uri !== '')
			customSave.disabled = true
			try {
				show(await api('PUT', '/api/oauth-clients', { customRedirectUris }))
				report(customRedirectsStatus, true)
			} catch (e) {
				report(customRedirectsStatus, false, t('mcp', 'Invalid redirect URIs: use at most 20 absolute https URIs, without user name, wildcard or fragment.'))
			} finally {
				customSave.disabled = false
			}
		})
	}

	/**
	 * Checkout upload limit of the status block, in whole MiB, through GET and PUT /apps/mcp/api/checkout-limit.
	 * Next to it: the effective limit and PHP's post_max_size ceiling.
	 */
	async function initCheckoutLimit() {
		const input = document.getElementById('mcp-checkout-limit')
		const save = document.getElementById('mcp-checkout-limit-save')
		const status = document.getElementById('mcp-checkout-limit-status')
		const info = document.getElementById('mcp-checkout-limit-info')
		const mib = (bytes) => Math.round(bytes / 1048576 * 10) / 10

		/** @param {object} data limit returned by the API, in bytes */
		function show(data) {
			// Round up and never show 0: a stored value below 1 MiB would fail the field's own min of 1 on the next save.
			input.value = String(Math.max(1, Math.ceil(data.configuredBytes / 1048576)))
			const effective = t('mcp', 'Effective limit: {size} MiB.', { size: mib(data.effectiveBytes) })
			const php = data.phpBytes > 0
				? t('mcp', 'PHP allows up to {size} MiB (post_max_size).', { size: mib(data.phpBytes) })
				: t('mcp', 'PHP sets no request size limit.')
			info.textContent = effective + ' ' + php
		}

		try {
			show(await api('GET', '/api/checkout-limit'))
		} catch (e) {
			info.textContent = t('mcp', 'Could not load the checkout upload limit.')
			return
		}

		save.addEventListener('click', async () => {
			const value = Number(input.value)
			if (!Number.isInteger(value) || value < 1) {
				status.textContent = t('mcp', 'Enter a whole number of MiB, at least 1.')
				flash(status, false)
				return
			}
			save.disabled = true
			try {
				show(await api('PUT', '/api/checkout-limit', { mib: value }))
				status.textContent = t('mcp', 'Saved')
				flash(status, true)
			} catch (e) {
				status.textContent = t('mcp', 'Not saved')
				flash(status, false)
				notifyError(t('mcp', 'Could not save the checkout upload limit.'))
			} finally {
				save.disabled = false
			}
		})
	}

	/**
	 * Server log section: the switch that hides the text of log messages in the log tools, through GET and PUT
	 * /apps/mcp/api/logs-privacy.
	 */
	async function initLogsPrivacy() {
		const box = document.getElementById('mcp-logs-hide-file-names')
		const status = document.getElementById('mcp-logs-hide-file-names-status')
		if (!box) {
			return
		}
		// The "Learn more" button opens the explanation that ships in the template; without JS it stays open.
		const more = document.getElementById('mcp-logs-privacy-more')
		const detail = document.getElementById('mcp-logs-privacy-detail')
		if (more && detail) {
			detail.hidden = true
			more.hidden = false
			more.addEventListener('click', () => {
				detail.hidden = !detail.hidden
				more.setAttribute('aria-expanded', detail.hidden ? 'false' : 'true')
			})
		}
		try {
			box.checked = (await api('GET', '/api/logs-privacy')).hideFileNames
		} catch (e) {
			box.disabled = true
			notifyError(t('mcp', 'Could not load the log privacy setting.'))
			return
		}
		box.addEventListener('change', async () => {
			const value = box.checked
			box.disabled = true
			try {
				box.checked = (await api('PUT', '/api/logs-privacy', { hideFileNames: value })).hideFileNames
				status.textContent = t('mcp', 'Saved')
				flash(status, true)
			} catch (e) {
				box.checked = !value
				status.textContent = t('mcp', 'Not saved')
				flash(status, false)
				notifyError(t('mcp', 'Could not change the log privacy setting.'))
			} finally {
				box.disabled = false
			}
		})
	}

	/**
	 * Server log section: the groups whose members may read the log besides the administrators, through
	 * GET and PUT /apps/mcp/api/logs-access. The picker locks while a save runs and each save carries
	 * the list it changed (`previous`), so the server answers 409 when another window or administrator
	 * saved first and a removed group is never put back. A listed group that was deleted shows as an
	 * option marked "(group removed)", to be removed.
	 */
	async function initLogsAccess() {
		const container = document.getElementById('mcp-logs-groups')
		const status = document.getElementById('mcp-logs-status')
		const summary = document.getElementById('mcp-logs-summary')
		if (!container || !window.McpAdminPicker) {
			return
		}
		let version = 0
		let picker = null

		/** @param {object} data state returned by the API */
		function show(data) {
			version = data.version
			const options = data.allGroups.map((group) => ({ id: group.id, label: group.displayName }))
			for (const id of data.missing) {
				options.push({ id: id, label: t('mcp', '{group} (group removed)', { group: id }, undefined, { escape: false }) })
			}
			if (!picker) {
				picker = window.McpAdminPicker.create({
					container,
					status,
					options,
					selectedIds: data.groups,
					searchLabel: t('mcp', 'Search groups'),
					listLabel: t('mcp', 'Server log groups'),
					emptyOptionsText: t('mcp', 'No groups found; only administrators can read the log.'),
					noMatchText: t('mcp', 'No match.'),
					save: async (nextIds, previousIds) => {
						return api('PUT', '/api/logs-access', { groups: nextIds, previous: previousIds, version: version })
					},
					onSaved: async (data) => {
						show(data)
						// The matrix shows the log column per user from the same gate, so it is reloaded.
						load()
					},
					onError: async (e) => {
						if (e.message === '409') {
							notifyError(t('mcp', 'The groups were changed in another window or by another administrator; the current list was loaded.'))
							load()
						} else {
							notifyError(t('mcp', 'Could not save the groups that may read the server log.'))
						}
						await reload()
					},
				})
			} else {
				picker.setOptions(options, data.groups)
			}
			paintSummary(data.groups, data.allGroups.map((group) => ({ id: group.id, label: group.displayName })))
		}

		/**
		 * The effective readers, as one line: groups are a gate on top of the per-user grant of the matrix.
		 *
		 * @param {string[]} groups the saved group ids
		 * @param {Array<{id: string, label: string}>} options the known groups, for their display names
		 */
		function paintSummary(groups, options) {
			if (!summary) {
				return
			}
			const readers = options.filter((option) => groups.includes(option.id))
			const names = readers.map((option) => option.label).join(', ')
			try {
				summary.textContent = readers.length === 0
					? t('mcp', 'Who may read the log: administrators only. Each of them also needs the “Server log” permission in the “Access and permissions” tab.')
					: n('mcp',
						'Who may read the log: administrators and the members of %n group ({names}). Each of them also needs the “Server log” permission in the “Access and permissions” tab.',
						'Who may read the log: administrators and the members of %n groups ({names}). Each of them also needs the “Server log” permission in the “Access and permissions” tab.',
						readers.length, { names }, { escape: false })
			} catch {
				// Optional explanatory text must never prevent managing the actual groups.
				summary.textContent = ''
			}
		}

		/** Loads the list again, after a conflict or a failure. */
		async function reload() {
			try {
				show(await api('GET', '/api/logs-access'))
			} catch (e) {
				if (picker) {
					picker.showFallback(t('mcp', 'Could not load the groups.'))
				} else {
					container.setAttribute('aria-busy', 'false')
					container.replaceChildren(el('p', { className: 'mcp-empty', textContent: t('mcp', 'Could not load the groups.') }))
				}
			}
		}

		await reload()
	}

	load()
	initTagsSection()
	initOauthClients()
	initCheckoutLimit()
	initLogsAccess()
	initLogsPrivacy()
})()
