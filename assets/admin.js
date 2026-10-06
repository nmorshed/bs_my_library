(function () {
    'use strict';
    const form = document.getElementById('bsml-settings');
    if (!form) return;
    let next = Date.now(), loading = 0;
    const owner = node => node.closest('.bsml-child-config, .bsml-tab-config');
    function syncEditors(root, remove) {
        root.querySelectorAll('.bsml-content-editor').forEach(textarea => {
            const editor = window.tinymce && tinymce.get(textarea.id);
            if (editor) editor.save();
            if (remove && textarea.dataset.editorReady) { wp.editor.remove(textarea.id); delete textarea.dataset.editorReady; }
        });
    }
    function refresh(root) {
        root.querySelectorAll('.bsml-tab-config, .bsml-child-config').forEach(row => {
            const type = row.querySelector('.bsml-section-type').value;
            row.querySelectorAll('[data-section-types]').forEach(group => {
                if (owner(group) === row) group.hidden = !group.dataset.sectionTypes.split(' ').includes(type);
            });
            row.querySelectorAll('[data-toggle-field]').forEach(group => {
                group.hidden = !row.querySelector('input[type="checkbox"][name$="[' + group.dataset.toggleField + ']"]').checked;
            });
            const taxonomy = row.querySelector('.bsml-taxonomy');
            if (taxonomy && !taxonomy.dataset.previous) taxonomy.dataset.previous = taxonomy.value;
        });
        root.querySelectorAll('[data-membership-toggle]').forEach(group => { group.hidden = !document.getElementById(group.dataset.membershipToggle).checked; });
        root.querySelectorAll('.bsml-content-editor').forEach(textarea => {
            if (textarea.dataset.editorReady || !textarea.getClientRects().length) return;
            textarea.id = textarea.id || 'bsml-editor-' + next++;
            wp.editor.initialize(textarea.id, {tinymce: {wpautop: true}, quicktags: true, mediaButtons: true});
            textarea.dataset.editorReady = '1';
        });
    }
    function panel(id) {
        if (!document.querySelector('[data-panel-id="' + id + '"]')) id = 'general';
        document.querySelectorAll('.bsml-settings-panel').forEach(node => { node.hidden = node.dataset.panelId !== id; });
        form.querySelectorAll('[data-panel]').forEach(button => {
            button.classList.toggle('nav-tab-active', button.dataset.panel === id);
            button.setAttribute('aria-pressed', String(button.dataset.panel === id));
        });
        history.replaceState(null, '', '#' + id);
        refresh(form);
    }
    form.addEventListener('click', function (event) {
        const button = event.target.closest('button');
        if (!button) return;
        if (button.dataset.panel) { panel(button.dataset.panel); return; }
        const row = owner(button);
        if (button.hasAttribute('data-bsml-remove')) { syncEditors(row, true); row.remove(); }
        if (button.dataset.bsmlMove) {
            syncEditors(row, true);
            if (button.dataset.bsmlMove === 'up' && row.previousElementSibling) row.parentNode.insertBefore(row, row.previousElementSibling);
            if (button.dataset.bsmlMove === 'down' && row.nextElementSibling) row.parentNode.insertBefore(row.nextElementSibling, row);
        }
        if (button.id === 'bsml-add-tab') {
            const id = next++;
            const html = document.getElementById('bsml-tab-template').innerHTML.replaceAll('[NEW]', '[' + id + ']');
            const list = document.getElementById('bsml-tabs');
            list.insertAdjacentHTML('beforeend', html);
            list.lastElementChild.open = true;
            list.lastElementChild.querySelector('input[name$="[id]"]').value = 'section-' + id;
        }
        if (button.hasAttribute('data-bsml-add-child')) {
            const id = next++;
            const html = row.querySelector('.bsml-child-template').innerHTML.replaceAll('[CHILD]', '[' + id + ']');
            const list = row.querySelector('.bsml-children');
            list.insertAdjacentHTML('beforeend', html);
            list.lastElementChild.querySelector('input[name$="[id]"]').value = 'submenu-' + id;
        }
        if (button.dataset.termMove) {
            const select = button.closest('[data-toggle-field]').querySelector('select');
            const selected = Array.from(select.selectedOptions);
            if (button.dataset.termMove === 'down') selected.reverse();
            selected.forEach(option => {
                const sibling = button.dataset.termMove === 'up' ? option.previousElementSibling : option.nextElementSibling;
                if (sibling && !sibling.selected) select.insertBefore(button.dataset.termMove === 'up' ? option : sibling, button.dataset.termMove === 'up' ? sibling : option);
            });
        }
        refresh(form);
    });
    form.addEventListener('toggle', () => refresh(form), true);
    form.addEventListener('input', function (event) {
        if (event.target.classList.contains('bsml-term-search')) {
            const query = event.target.value.toLowerCase();
            for (const option of event.target.nextElementSibling.options) option.hidden = !option.text.toLowerCase().includes(query) && !option.selected;
        }
        if (event.target.name && event.target.name.endsWith('[label]')) {
            const row = owner(event.target);
            if (row) row.querySelector('summary').textContent = event.target.value || 'Untitled section';
            const tier = event.target.closest('.bsml-tier-card');
            if (tier) tier.querySelector('summary strong').textContent = event.target.value || 'Untitled tier';
        }
    });
    form.addEventListener('change', async function (event) {
        refresh(form);
        const select = event.target;
        if (!select.classList.contains('bsml-taxonomy')) return;
        const row = owner(select), notice = row.querySelector('.bsml-taxonomy-notice');
        select.disabled = true; loading++; notice.textContent = 'Loading terms…';
        try {
            const url = new URL(ajaxurl, window.location.href);
            url.search = new URLSearchParams({action: 'bsml_terms', nonce: BSMLAdmin.nonce, taxonomy: select.value});
            const response = await fetch(url, {credentials: 'same-origin', cache: 'no-store'});
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error();
            row.querySelectorAll('.bsml-library-scope select, [data-toggle-field="show_terms"] select').forEach(terms => {
                terms.replaceChildren(...result.data.terms.map(term => new Option(term.label, term.id)));
            });
            row.querySelector('input[name$="[filter_map]"]').value = '{}';
            select.dataset.previous = select.value;
            notice.textContent = result.data.attached ? 'Terms loaded. Select the included terms for this section.' : 'This taxonomy is not attached to the section’s content type. Check that the relevant content plugin is active.';
        } catch (error) {
            select.value = select.dataset.previous;
            notice.textContent = 'Terms could not be loaded. Your previous taxonomy and selections were kept. Please try again.';
        } finally { select.disabled = false; loading--; }
    });
    form.addEventListener('submit', function (event) {
        if (loading) { event.preventDefault(); return; }
        syncEditors(form, false);
        document.querySelectorAll('#bsml-tabs > .bsml-tab-config').forEach(function (row, index) {
            row.querySelectorAll('[name]').forEach(input => { input.name = input.name.replace(/\[tabs\]\[[^\]]+\]/, '[tabs][' + index + ']'); });
            row.querySelectorAll('.bsml-children > .bsml-child-config').forEach((child, childIndex) => {
                child.querySelectorAll('[name]').forEach(input => { input.name = input.name.replace(/\[children\]\[[^\]]+\]/, '[children][' + childIndex + ']'); });
            });
        });
    });
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', () => panel(location.hash.slice(1)));
    else panel(location.hash.slice(1));
})();
