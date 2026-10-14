/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * Generic editable list of short texts for the admin page, exposed as window.McpAdminListEditor.
 *
 * One entry per row with its own Remove button, then a field and an Add button (Enter adds too). The
 * editor only edits: saving is the caller's, through its own Save button, and validation stays on the
 * server. Each entry is normalized by the caller's function, which may refuse it with a message shown
 * next to the field. While the list differs from the last saved one, the editor says so. Plain browser
 * JavaScript, no build step; strings go through t('mcp', …) for translation.
 */
(function () {
	'use strict'

	let editorCount = 0

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

	/**
	 * @param {object} spec configuration
	 * @param {HTMLElement} spec.container element that receives the editor
	 * @param {string[]} spec.items entries to start with, as saved
	 * @param {string} spec.inputLabel accessible name of the field
	 * @param {string} [spec.placeholder] placeholder of the field
	 * @param {string} spec.emptyText shown when the list is empty
	 * @param {function(string): {value: string|null, error: string}} spec.normalize turns a typed text into an entry, or refuses it
	 * @param {function(string): string} spec.removeLabel accessible name of the Remove button of an entry
	 * @param {function(string[]): void} [spec.onChange] called after every edit of the list (not after setSaved)
	 * @return {{getItems: function(): string[], setSaved: function(string[]): void, isDirty: function(): boolean}}
	 */
	function create(spec) {
		const id = 'mcp-list-' + (++editorCount)
		let items = spec.items.slice()
		let saved = spec.items.slice()
		const list = el('ul', { className: 'mcp-list-items' })
		const input = el('input', { type: 'text', id: id + '-input', placeholder: spec.placeholder || '', spellcheck: false, autocomplete: 'off' })
		input.setAttribute('aria-label', spec.inputLabel)
		const add = el('button', { type: 'button', textContent: t('mcp', 'Add') })
		const message = el('span', { className: 'mcp-list-message', id: id + '-message' })
		message.setAttribute('aria-live', 'polite')
		input.setAttribute('aria-describedby', message.id)
		const dirty = el('span', { className: 'mcp-list-dirty', textContent: t('mcp', 'Unsaved changes'), hidden: true })

		function changed() {
			dirty.hidden = !isDirty()
			if (spec.onChange) {
				spec.onChange(items.slice())
			}
		}

		function render() {
			if (items.length === 0) {
				list.replaceChildren(el('li', { className: 'mcp-list-empty', textContent: spec.emptyText }))
				return
			}
			list.replaceChildren(...items.map((item, index) => {
				const remove = el('button', { type: 'button', className: 'mcp-list-remove', textContent: t('mcp', 'Remove') })
				remove.setAttribute('aria-label', spec.removeLabel(item))
				remove.addEventListener('click', () => {
					items.splice(index, 1)
					render()
					changed()
					// The row is gone; the focus goes to the next Remove button, or to the field.
					const next = list.querySelectorAll('.mcp-list-remove')[Math.min(index, items.length - 1)]
					;(next || input).focus()
				})
				return el('li', {}, [el('code', { textContent: item }), remove])
			}))
		}

		function addTyped() {
			const result = spec.normalize(input.value)
			if (result.value === null) {
				message.textContent = result.error
				return
			}
			if (items.includes(result.value)) {
				message.textContent = t('mcp', '{entry} is already in the list.', { entry: result.value }, undefined, { escape: false })
				return
			}
			items.push(result.value)
			input.value = ''
			message.textContent = ''
			render()
			changed()
		}

		add.addEventListener('click', addTyped)
		input.addEventListener('keydown', (event) => {
			if (event.key === 'Enter') {
				event.preventDefault()
				addTyped()
			}
		})
		input.addEventListener('input', () => { message.textContent = '' })

		function isDirty() {
			return items.length !== saved.length || items.some((item, index) => item !== saved[index])
		}

		spec.container.replaceChildren(list, el('div', { className: 'mcp-list-add' }, [input, add, dirty]), message)
		render()
		return {
			getItems: () => items.slice(),
			setSaved: (next) => {
				// What the server saved; not an edit, so onChange is not called.
				items = next.slice()
				saved = next.slice()
				render()
				dirty.hidden = true
			},
			isDirty,
		}
	}

	window.McpAdminListEditor = { create }
})()
