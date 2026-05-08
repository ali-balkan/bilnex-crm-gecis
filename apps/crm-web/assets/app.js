(function () {
    const qs = (selector, root = document) => root.querySelector(selector);
    const qsa = (selector, root = document) => Array.from(root.querySelectorAll(selector));

    qsa('[data-open-dialog]').forEach((button) => {
        button.addEventListener('click', () => {
            const dialog = qs(button.dataset.openDialog);
            if (dialog && typeof dialog.showModal === 'function') {
                dialog.showModal();
            }
        });
    });

    qsa('[data-close-dialog]').forEach((button) => {
        button.addEventListener('click', () => {
            const dialog = button.closest('dialog');
            if (dialog) dialog.close();
        });
    });

    qsa('tr[data-href]').forEach((row) => {
        row.addEventListener('click', (event) => {
            if (event.target.closest('a, button, input, select, textarea, form')) return;
            window.location.href = row.dataset.href;
        });
    });

    qsa('[data-export-table]').forEach((button) => {
        button.addEventListener('click', () => {
            const table = qs(button.dataset.exportTable);
            if (!table) return;
            const rows = qsa('tr', table).map((row) => qsa('th,td', row).map((cell) => {
                const value = cell.innerText.replace(/\s+/g, ' ').trim().replace(/"/g, '""');
                return `"${value}"`;
            }).join(';'));
            const blob = new Blob(['\uFEFF' + rows.join('\n')], { type: 'text/csv;charset=utf-8' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = button.dataset.filename || 'rapor.csv';
            link.click();
            URL.revokeObjectURL(link.href);
        });
    });

    const customerDialog = qs('#sql-customer-dialog');
    if (customerDialog) {
        const queryInput = qs('[data-sql-customer-query]', customerDialog);
        const searchButton = qs('[data-sql-customer-search]', customerDialog);
        const results = qs('[data-sql-customer-results]', customerDialog);
        let activePicker = null;
        let searchTimer = null;

        const setResults = (html) => {
            if (results) results.innerHTML = html;
        };
        const escapeHtml = (value) => String(value || '').replace(/[&<>"']/g, (char) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;',
        }[char]));

        const customerSearch = async () => {
            const query = (queryInput && queryInput.value ? queryInput.value : '').trim();
            if (query.length < 2) {
                setResults('Arama için en az 2 karakter yazın.');
                return;
            }
            setResults('<div class="muted">SQL cariler aranıyor...</div>');
            const url = new URL(customerDialog.dataset.searchUrl, window.location.href);
            url.searchParams.set('q', query);
            try {
                const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
                const payload = await response.json();
                if (payload.error) {
                    setResults('<div class="alert alert-danger">' + payload.error + '</div>');
                    return;
                }
                if (!payload.items || payload.items.length === 0) {
                    setResults('<div class="empty-state">Bu aramayla SQL cari bulunamadı.</div>');
                    return;
                }
                setResults(payload.items.map((item) => (
                    '<button class="sql-customer-result" type="button" data-sql-customer-select data-id="' + escapeHtml(item.id) + '" data-label="' + escapeHtml(item.label) + '">' +
                    '<strong>' + escapeHtml(item.name || '-') + '</strong>' +
                    '<span>' + escapeHtml(item.code || 'Kod yok') + ' · ' + escapeHtml(item.type || 'Cari') + ' · SQL #' + escapeHtml(item.id) + '</span>' +
                    '</button>'
                )).join(''));
            } catch (error) {
                setResults('<div class="alert alert-danger">SQL cari araması yapılamadı.</div>');
            }
        };

        qsa('[data-open-sql-customer-picker]').forEach((button) => {
            button.addEventListener('click', () => {
                activePicker = button.closest('[data-sql-customer-picker]');
                if (typeof customerDialog.showModal === 'function') {
                    customerDialog.showModal();
                }
                if (queryInput) {
                    queryInput.focus();
                    queryInput.select();
                }
            });
        });

        qsa('[data-clear-sql-customer]').forEach((button) => {
            button.addEventListener('click', () => {
                const picker = button.closest('[data-sql-customer-picker]');
                const idInput = picker ? qs('[data-sql-customer-id]', picker.closest('form')) : null;
                const label = picker ? qs('[data-sql-customer-label]', picker) : null;
                if (idInput) idInput.value = '';
                if (label) label.textContent = 'Cari seçmek için tıklayın';
            });
        });

        if (searchButton) {
            searchButton.addEventListener('click', customerSearch);
        }
        if (queryInput) {
            queryInput.addEventListener('input', () => {
                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(customerSearch, 350);
            });
            queryInput.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    customerSearch();
                }
            });
        }

        customerDialog.addEventListener('click', (event) => {
            const selectButton = event.target.closest('[data-sql-customer-select]');
            if (!selectButton || !activePicker) return;
            const form = activePicker.closest('form');
            const idInput = form ? qs('[data-sql-customer-id]', form) : null;
            const label = qs('[data-sql-customer-label]', activePicker);
            if (idInput) idInput.value = selectButton.dataset.id || '';
            if (label) label.textContent = selectButton.dataset.label || 'SQL cari seçildi';
            customerDialog.close();
        });
    }
}());
