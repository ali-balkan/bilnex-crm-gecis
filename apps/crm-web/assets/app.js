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
}());
