/**
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * "Your access" on the personal page: what an MCP client may do with the signed-in user's account and why, read
 * from GET /apps/mcp/api/my/access. It only reads.
 */
(function () {
	'use strict'

	const summary = document.getElementById('mcp-my-access-summary')
	const table = document.getElementById('mcp-my-access-table')
	if (!summary || !table) {
		return
	}

	const modules = {
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
	const operations = {
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
		restore: t('mcp', 'restore'),
	}

	/**
	 * @param {string} tag element name
	 * @param {object} [props] properties to assign
	 * @return {HTMLElement}
	 */
	function el(tag, props) {
		return Object.assign(document.createElement(tag), props || {})
	}

	/**
	 * @param {object} row a decision of the API
	 * @return {string} where it comes from, in the user's words
	 */
	function why(row) {
		if (row.source === 'override') {
			return t('mcp', 'Set for you by your administrator.')
		}
		if (row.source === 'group') {
			return row.deny.length > 0
				? t('mcp', 'Denied for your group {groups}.', { groups: row.deny.join(', ') }, undefined, { escape: false })
				: t('mcp', 'Allowed for your group {groups}.', { groups: row.allow.join(', ') }, undefined, { escape: false })
		}
		return t('mcp', 'Default setting.')
	}

	fetch(OC.generateUrl('/apps/mcp/api/my/access'), { headers: { Accept: 'application/json', requesttoken: OC.requestToken } })
		.then((response) => response.ok ? response.json() : Promise.reject(new Error(String(response.status))))
		.then((data) => {
			const blockers = {
				service_off: t('mcp', 'The MCP service is off.'),
				account_disabled: t('mcp', 'Your account is disabled.'),
				not_eligible: t('mcp', 'Your administrator has not allowed your account to connect.'),
				not_connected: t('mcp', 'Your connection is off; turn it on above.'),
			}
			summary.textContent = data.canConnect
				? t('mcp', 'You can use MCP now. These permissions are in effect:')
				: t('mcp', 'You cannot use MCP now:') + ' ' + data.blockers.map((code) => blockers[code] || code).join(' ')
			const body = table.tBodies[0]
			for (const row of data.permissions) {
				if (!row.available) {
					// An app you do not have is not something an MCP client could use either.
					continue
				}
				const tr = el('tr')
				const name = el('th', { scope: 'row', textContent: (modules[row.module] || row.module) + ': ' + (operations[row.operation] || row.operation) })
				const result = el('td', { className: row.effective ? 'mcp-yes' : 'mcp-no', textContent: row.result ? t('mcp', 'Allowed') : t('mcp', 'Denied') })
				tr.append(name, result, el('td', { textContent: why(row) }))
				body.append(tr)
			}
			table.hidden = false
		})
		.catch(() => {
			summary.textContent = t('mcp', 'Could not load your access.')
		})
})()
