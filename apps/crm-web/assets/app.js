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

    qsa('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm || 'Bu kayıt silinsin mi?')) {
                event.preventDefault();
            }
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
                const form = picker ? picker.closest('form') : null;
                const idInput = form ? qs('[data-sql-customer-id]', form) : null;
                const companyInput = form ? qs('input[name="company_id"]', form) : null;
                const label = picker ? qs('[data-sql-customer-label]', picker) : null;
                if (idInput) idInput.value = '';
                if (companyInput) companyInput.value = '';
                if (label) label.textContent = 'Cari seçmeden de kaydedebilirsiniz';
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
            const companyInput = form ? qs('input[name="company_id"]', form) : null;
            const label = qs('[data-sql-customer-label]', activePicker);
            if (idInput) idInput.value = selectButton.dataset.id || '';
            if (companyInput) companyInput.value = '';
            if (label) label.textContent = selectButton.dataset.label || 'SQL cari seçildi';
            customerDialog.close();
        });
    }

    qsa('[data-company-autocomplete]').forEach((root) => {
        const input = qs('[data-company-search-input]', root);
        const companyIdInput = qs('[data-company-id]', root);
        const sqlCustomerIdInput = qs('[data-company-sql-id]', root);
        const results = qs('[data-company-search-results]', root);
        const form = root.closest('form');
        let searchTimer = null;
        let requestController = null;
        let selectedLabel = input ? input.value.trim() : '';

        if (!input || !results || !root.dataset.searchUrl) return;

        const escapeHtml = (value) => String(value || '').replace(/[&<>"']/g, (char) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;',
        }[char]));

        const showResults = (html) => {
            results.innerHTML = html;
            results.hidden = false;
        };

        const hideResults = () => {
            results.hidden = true;
            results.innerHTML = '';
        };

        const clearSelectionIfChanged = () => {
            if (input.value.trim() === selectedLabel) return;
            if (companyIdInput) companyIdInput.value = '';
            if (sqlCustomerIdInput) sqlCustomerIdInput.value = '';
        };

        const renderItems = (items) => {
            if (!items || items.length === 0) {
                showResults('<div class="company-autocomplete-hint">Bu aramayla cari bulunamadı. Sağdaki Yeni cari ekle ile kayıt açabilirsiniz.</div>');
                return;
            }

            showResults(items.map((item) => {
                const meta = [
                    item.code || 'Kod yok',
                    item.type || 'Cari',
                    item.meta || '',
                    item.sql_customer_id ? 'SQL #' + item.sql_customer_id : '',
                ].filter(Boolean).join(' · ');

                return '<button class="company-autocomplete-result" type="button" data-company-select data-company-id="' + escapeHtml(item.company_id || '') + '" data-sql-customer-id="' + escapeHtml(item.sql_customer_id || '') + '" data-label="' + escapeHtml(item.label || '') + '">' +
                    '<strong>' + escapeHtml(item.name || item.label || '-') + '</strong>' +
                    '<span>' + escapeHtml(meta) + '</span>' +
                    '</button>';
            }).join(''));
        };

        const searchCompanies = async () => {
            const query = input.value.trim();
            if (query.length < 3) {
                hideResults();
                return;
            }

            if (requestController) requestController.abort();
            requestController = new AbortController();
            showResults('<div class="company-autocomplete-hint">Cariler aranıyor...</div>');

            const url = new URL(root.dataset.searchUrl, window.location.href);
            url.searchParams.set('q', query);

            try {
                const response = await fetch(url.toString(), {
                    headers: { Accept: 'application/json' },
                    signal: requestController.signal,
                });
                const payload = await response.json();
                if (payload.error) {
                    showResults('<div class="company-autocomplete-hint danger">Cari araması yapılamadı: ' + escapeHtml(payload.error) + '</div>');
                    return;
                }
                renderItems(payload.items || []);
            } catch (error) {
                if (error.name === 'AbortError') return;
                showResults('<div class="company-autocomplete-hint danger">Cari araması yapılamadı.</div>');
            }
        };

        input.addEventListener('input', () => {
            clearSelectionIfChanged();
            window.clearTimeout(searchTimer);
            const query = input.value.trim();
            if (query.length < 3) {
                if (query.length > 0) {
                    showResults('<div class="company-autocomplete-hint">Arama için en az 3 karakter yazın.</div>');
                } else {
                    hideResults();
                }
                return;
            }
            searchTimer = window.setTimeout(searchCompanies, 300);
        });

        input.addEventListener('focus', () => {
            if (input.value.trim().length >= 3 && !(companyIdInput && companyIdInput.value) && !(sqlCustomerIdInput && sqlCustomerIdInput.value)) {
                searchCompanies();
            }
        });

        input.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' || results.hidden) return;
            const firstItem = qs('[data-company-select]', results);
            if (!firstItem) return;
            event.preventDefault();
            firstItem.click();
        });

        results.addEventListener('click', (event) => {
            const button = event.target.closest('[data-company-select]');
            if (!button) return;
            input.value = button.dataset.label || '';
            selectedLabel = input.value.trim();
            if (companyIdInput) companyIdInput.value = button.dataset.companyId || '';
            if (sqlCustomerIdInput) sqlCustomerIdInput.value = button.dataset.sqlCustomerId || '';
            hideResults();
            input.focus();
        });

        if (form) {
            form.addEventListener('submit', (event) => {
                if ((companyIdInput && companyIdInput.value) || (sqlCustomerIdInput && sqlCustomerIdInput.value) || input.value.trim() === '') {
                    return;
                }
                event.preventDefault();
                showResults('<div class="company-autocomplete-hint danger">Devam etmek için listeden bir cari seçin.</div>');
                input.focus();
            });
        }

        document.addEventListener('click', (event) => {
            if (!root.contains(event.target)) hideResults();
        });
    });

    const taxOfficeDialog = qs('#tax-office-dialog');
    if (taxOfficeDialog) {
        const queryInput = qs('[data-tax-office-query]', taxOfficeDialog);
        const searchButton = qs('[data-tax-office-search]', taxOfficeDialog);
        const results = qs('[data-tax-office-results]', taxOfficeDialog);
        let activePicker = null;
        let searchTimer = null;
        const escapeHtml = (value) => String(value || '').replace(/[&<>"']/g, (char) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;',
        }[char]));
        const setResults = (html) => {
            if (results) results.innerHTML = html;
        };
        const taxOfficeSearch = async () => {
            const query = (queryInput && queryInput.value ? queryInput.value : '').trim();
            setResults('<div class="muted">Vergi daireleri aranıyor...</div>');
            const url = new URL(taxOfficeDialog.dataset.searchUrl, window.location.href);
            if (query !== '') {
                url.searchParams.set('q', query);
            }
            try {
                const response = await fetch(url.toString(), { headers: { Accept: 'application/json' } });
                const payload = await response.json();
                if (!payload.items || payload.items.length === 0) {
                    setResults('<div class="empty-state">Bu aramayla vergi dairesi bulunamadı.</div>');
                    return;
                }
                setResults(payload.items.map((item) => (
                    '<button class="sql-customer-result" type="button" data-tax-office-select data-code="' + escapeHtml(item.code) + '" data-name="' + escapeHtml(item.name) + '" data-label="' + escapeHtml(item.label) + '">' +
                    '<strong>' + escapeHtml(item.name || '-') + '</strong>' +
                    '<span>' + escapeHtml(item.city || '-') + ' · ' + escapeHtml(item.district || '-') + (item.code ? ' · ' + escapeHtml(item.code) : '') + '</span>' +
                    '</button>'
                )).join(''));
            } catch (error) {
                setResults('<div class="alert alert-danger">Vergi dairesi listesi okunamadı.</div>');
            }
        };

        qsa('[data-open-tax-office-picker]').forEach((button) => {
            button.addEventListener('click', () => {
                activePicker = button.closest('[data-tax-office-picker]');
                const form = activePicker ? activePicker.closest('form') : null;
                const currentName = form ? qs('[data-tax-office-name]', form) : null;
                if (queryInput && currentName && currentName.value) {
                    queryInput.value = currentName.value;
                }
                if (typeof taxOfficeDialog.showModal === 'function') {
                    taxOfficeDialog.showModal();
                }
                taxOfficeSearch();
                if (queryInput) {
                    queryInput.focus();
                    queryInput.select();
                }
            });
        });

        if (searchButton) {
            searchButton.addEventListener('click', taxOfficeSearch);
        }
        if (queryInput) {
            queryInput.addEventListener('input', () => {
                window.clearTimeout(searchTimer);
                searchTimer = window.setTimeout(taxOfficeSearch, 250);
            });
            queryInput.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    taxOfficeSearch();
                }
            });
        }

        taxOfficeDialog.addEventListener('click', (event) => {
            const selectButton = event.target.closest('[data-tax-office-select]');
            if (!selectButton || !activePicker) return;
            const form = activePicker.closest('form');
            const codeInput = form ? qs('[data-tax-office-code]', form) : null;
            const nameInput = form ? qs('[data-tax-office-name]', form) : null;
            if (codeInput) codeInput.value = selectButton.dataset.code || '';
            if (nameInput) nameInput.value = selectButton.dataset.name || '';
            taxOfficeDialog.close();
        });
    }
}());
