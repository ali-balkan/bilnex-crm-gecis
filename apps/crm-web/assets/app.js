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
            const skipIndexes = new Set(qsa('thead th', table).reduce((indexes, cell, index) => {
                const label = cell.innerText.replace(/\s+/g, ' ').trim().toLocaleLowerCase('tr-TR');
                if (cell.hasAttribute('data-export-skip') || label.includes('sql')) {
                    indexes.push(index);
                }
                return indexes;
            }, []));
            const rows = qsa('tr', table).map((row) => qsa('th,td', row).filter((_, index) => !skipIndexes.has(index)).map((cell) => {
                const value = cell.innerText.replace(/\s+/g, ' ').trim().replace(/"/g, '""');
                return `"${value}"`;
            }).join(';')).filter(Boolean);
            const blob = new Blob(['\uFEFF' + rows.join('\n')], { type: 'text/csv;charset=utf-8' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = button.dataset.filename || 'rapor.csv';
            link.click();
            URL.revokeObjectURL(link.href);
        });
    });

    qsa('[data-stat-total-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const value = button.parentElement ? qs('.stat-total-value', button.parentElement) : null;
            if (!value) return;
            const nextExpanded = button.getAttribute('aria-expanded') !== 'true';
            button.setAttribute('aria-expanded', nextExpanded ? 'true' : 'false');
            value.hidden = !nextExpanded;
        });
    });

    qsa('.interaction-filter-grid').forEach((form) => {
        const scope = qs('[data-interaction-scope]', form);
        const user = qs('[data-interaction-user]', form);
        const role = qs('[data-interaction-role]', form);
        if (!scope) return;

        if (user) {
            user.addEventListener('change', () => {
                if (user.value && user.value !== '0') {
                    scope.value = 'user';
                    if (role) role.value = '';
                }
            });
        }

        if (role) {
            role.addEventListener('change', () => {
                if (role.value) {
                    scope.value = 'role';
                    if (user) user.value = '0';
                }
            });
        }

        scope.addEventListener('change', () => {
            if (scope.value === 'all' || scope.value === 'mine') {
                if (user) user.value = '0';
                if (role) role.value = '';
            }
        });
    });

    qsa('[data-opportunity-kanban]').forEach((board) => {
        const updateUrl = board.dataset.updateUrl || '';
        const csrfToken = board.dataset.csrfToken || '';
        let draggedCard = null;
        let sourceColumn = null;
        let clickAfterDrag = false;

        const updateCounts = () => {
            qsa('[data-kanban-stage]', board).forEach((column) => {
                const count = qsa('[data-opportunity-card]', column).length;
                const badge = qs('.badge', column);
                if (badge) badge.textContent = String(count);
            });
        };

        const setColumnActive = (column, active) => {
            column.classList.toggle('drag-over', active);
        };

        board.addEventListener('dragstart', (event) => {
            const card = event.target.closest('[data-opportunity-card]');
            if (!card || !board.contains(card)) return;
            draggedCard = card;
            sourceColumn = card.closest('[data-kanban-stage]');
            clickAfterDrag = true;
            card.classList.add('dragging');
            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', card.dataset.opportunityId || '');
            }
        });

        board.addEventListener('dragend', () => {
            if (draggedCard) {
                draggedCard.classList.remove('dragging');
            }
            qsa('[data-kanban-stage]', board).forEach((column) => setColumnActive(column, false));
            draggedCard = null;
            sourceColumn = null;
            window.setTimeout(() => {
                clickAfterDrag = false;
            }, 0);
        });

        board.addEventListener('click', (event) => {
            const card = event.target.closest('[data-opportunity-card]');
            if (!card || !clickAfterDrag) return;
            event.preventDefault();
        });

        qsa('[data-kanban-stage]', board).forEach((column) => {
            column.addEventListener('dragover', (event) => {
                if (!draggedCard) return;
                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';
                setColumnActive(column, true);
            });

            column.addEventListener('dragleave', (event) => {
                if (!column.contains(event.relatedTarget)) {
                    setColumnActive(column, false);
                }
            });

            column.addEventListener('drop', async (event) => {
                event.preventDefault();
                setColumnActive(column, false);
                if (!draggedCard || !updateUrl) return;

                const card = draggedCard;
                const targetStage = column.dataset.kanbanStage || '';
                const previousColumn = sourceColumn;
                if (!targetStage || card.dataset.currentStage === targetStage) return;

                column.appendChild(card);
                const previousStage = card.dataset.currentStage || '';
                card.dataset.currentStage = targetStage;
                updateCounts();

                const formData = new FormData();
                formData.set('csrf_token', csrfToken);
                formData.set('id', card.dataset.opportunityId || '');
                formData.set('stage', targetStage);

                try {
                    const response = await fetch(updateUrl, {
                        method: 'POST',
                        body: formData,
                        headers: { Accept: 'application/json' },
                    });
                    const payload = await response.json();
                    if (!response.ok || !payload.ok) {
                        throw new Error(payload.message || 'Fırsat aşaması güncellenemedi.');
                    }
                } catch (error) {
                    if (previousColumn) previousColumn.appendChild(card);
                    card.dataset.currentStage = previousStage;
                    updateCounts();
                    window.alert(error.message || 'Fırsat aşaması güncellenemedi.');
                }
            });
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
                    '<span>' + escapeHtml([item.type || 'Cari', item.meta || ''].filter(Boolean).join(' · ')) + '</span>' +
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
                if (label) label.textContent = picker.dataset.emptyLabel || 'Cari seçmeden de kaydedebilirsiniz';
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
                    item.type || 'Cari',
                    item.meta || '',
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

    const notificationConfig = window.crmAssignmentNotifications || null;
    if (notificationConfig && notificationConfig.pollUrl && notificationConfig.readUrl) {
        let notificationAudioContext = null;
        let notificationPolling = false;
        const shownNotificationIds = new Set();

        const escapeHtml = (value) => String(value || '').replace(/[&<>"']/g, (char) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;',
        }[char]));

        const notificationContext = () => {
            if (!notificationAudioContext) {
                notificationAudioContext = new (window.AudioContext || window.webkitAudioContext)();
            }
            return notificationAudioContext;
        };

        const playTone = (start, frequency, duration, gainValue, type = 'triangle') => {
            const ctx = notificationContext();
            const oscillator = ctx.createOscillator();
            const gain = ctx.createGain();
            oscillator.type = type;
            oscillator.frequency.setValueAtTime(frequency, start);
            gain.gain.setValueAtTime(0.0001, start);
            gain.gain.exponentialRampToValueAtTime(gainValue, start + 0.015);
            gain.gain.exponentialRampToValueAtTime(0.0001, start + duration);
            oscillator.connect(gain);
            gain.connect(ctx.destination);
            oscillator.start(start);
            oscillator.stop(start + duration + 0.03);
        };

        const playAssignmentSound = async () => {
            try {
                const ctx = notificationContext();
                if (ctx.state === 'suspended') {
                    await ctx.resume();
                }
                const now = ctx.currentTime + 0.02;
                for (let offset = 0; offset < 5; offset += 0.55) {
                    playTone(now + offset, 740, 0.12, 0.13);
                    playTone(now + offset + 0.18, 980, 0.14, 0.13);
                }
            } catch (error) {
                // Browser audio permissions may require one user interaction first.
            }
        };

        const unlockAudio = () => {
            try {
                const ctx = notificationContext();
                if (ctx.state === 'suspended') {
                    ctx.resume();
                }
            } catch (error) {
                // No-op.
            }
        };

        ['pointerdown', 'keydown'].forEach((eventName) => {
            window.addEventListener(eventName, unlockAudio, { once: true, passive: true });
        });

        const notificationStack = document.createElement('div');
        notificationStack.className = 'assignment-notification-stack';
        notificationStack.setAttribute('aria-live', 'polite');
        document.body.appendChild(notificationStack);

        const showNotification = (item) => {
            const toast = document.createElement('article');
            toast.className = 'assignment-toast';
            const target = item.target_url || '';
            toast.innerHTML = (
                '<button type="button" class="assignment-toast-close" aria-label="Bildirimi kapat">×</button>' +
                '<strong>' + escapeHtml(item.title || 'Yeni atama') + '</strong>' +
                (item.message ? '<span>' + escapeHtml(item.message) + '</span>' : '') +
                (item.created_by_name ? '<small>Atayan: ' + escapeHtml(item.created_by_name) + '</small>' : '') +
                (target ? '<a href="' + escapeHtml(target) + '">Aç</a>' : '')
            );
            const close = qs('.assignment-toast-close', toast);
            if (close) {
                close.addEventListener('click', () => toast.remove());
            }
            notificationStack.appendChild(toast);
            window.setTimeout(() => {
                toast.classList.add('is-fading');
                window.setTimeout(() => toast.remove(), 260);
            }, 14000);
        };

        const markNotificationsRead = async (ids) => {
            if (!ids.length) return;
            const formData = new FormData();
            formData.set('csrf_token', notificationConfig.csrfToken || '');
            ids.forEach((id) => formData.append('ids[]', id));
            try {
                await fetch(notificationConfig.readUrl, {
                    method: 'POST',
                    body: formData,
                    headers: { Accept: 'application/json' },
                });
            } catch (error) {
                // The next poll can try again if marking fails.
            }
        };

        const pollNotifications = async () => {
            if (notificationPolling) return;
            notificationPolling = true;
            try {
                const response = await fetch(notificationConfig.pollUrl, {
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                });
                const payload = await response.json();
                const items = (payload.items || []).filter((item) => {
                    const id = Number(item.id || 0);
                    if (!id || shownNotificationIds.has(id)) return false;
                    shownNotificationIds.add(id);
                    return true;
                });
                if (items.length) {
                    items.forEach(showNotification);
                    playAssignmentSound();
                    markNotificationsRead(items.map((item) => item.id));
                }
            } catch (error) {
                // Keep polling quietly; notification fetch should never block CRM usage.
            } finally {
                notificationPolling = false;
            }
        };

        window.setTimeout(pollNotifications, 1200);
        window.setInterval(pollNotifications, 5000);
    }
}());
