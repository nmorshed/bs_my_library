(function () {
    'use strict';
    const form = document.getElementById('bsml-settings');
    if (!form) return;
    let next = Date.now();
    form.addEventListener('click', function (event) {
        const button = event.target.closest('button');
        if (!button) return;
        const row = button.closest('.bsml-tab-config');
        if (button.hasAttribute('data-bsml-remove')) row.remove();
        if (button.dataset.bsmlMove === 'up' && row.previousElementSibling) row.parentNode.insertBefore(row, row.previousElementSibling);
        if (button.dataset.bsmlMove === 'down' && row.nextElementSibling) row.parentNode.insertBefore(row.nextElementSibling, row);
        if (button.id === 'bsml-add-tab') {
            const html = document.getElementById('bsml-tab-template').innerHTML.replaceAll('[NEW]', '[' + next++ + ']');
            document.getElementById('bsml-tabs').insertAdjacentHTML('beforeend', html);
            document.getElementById('bsml-tabs').lastElementChild.open = true;
        }
    });
    form.addEventListener('input', function (event) {
        if (event.target.classList.contains('bsml-term-search')) {
            const query = event.target.value.toLowerCase();
            for (const option of event.target.nextElementSibling.options) option.hidden = !option.text.toLowerCase().includes(query) && !option.selected;
        }
    });
    form.addEventListener('submit', function () {
        document.querySelectorAll('#bsml-tabs .bsml-tab-config').forEach(function (row, index) {
            row.querySelectorAll('[name]').forEach(function (input) { input.name = input.name.replace(/\[tabs\]\[[^\]]+\]/, '[tabs][' + index + ']'); });
        });
    });
})();
