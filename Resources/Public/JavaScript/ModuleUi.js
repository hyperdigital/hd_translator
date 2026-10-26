/**
 * Small conveniences shared by every screen of the module.
 *
 * - a form that was submitted says so, because an export of a whole page tree takes seconds
 *   during which the button otherwise looks untouched and gets clicked again
 * - a table marked sortable can be sorted by clicking its headers
 */

/* ------------------------------------------------- submitting takes a while */

document.querySelectorAll('.hd-translator form').forEach((form) => {
    form.addEventListener('submit', () => {
        const button = form.querySelector('button[type=submit], button:not([type])');
        if (!button || button.dataset.busy) return;

        button.dataset.busy = '1';
        button.disabled = true;
        button.classList.add('is-busy');

        const label = button.dataset.busyLabel;
        if (label) {
            button.dataset.idleLabel = button.innerHTML;
            button.textContent = label;
        }

        // a download leaves the page standing, so the button has to come back on its own
        window.setTimeout(() => {
            button.disabled = false;
            button.classList.remove('is-busy');
            delete button.dataset.busy;
            if (button.dataset.idleLabel) button.innerHTML = button.dataset.idleLabel;
        }, 20000);
    });
});

/* --------------------------------------------------------- sortable tables */

document.querySelectorAll('table.hd-translator-sortable').forEach((table) => {
    const body = table.querySelector('tbody');
    if (!body) return;

    const headers = [...table.querySelectorAll('thead th[data-sort]')];

    const valueOf = (row, index, type) => {
        const cell = row.children[index];
        if (!cell) return type === 'number' ? Number.NEGATIVE_INFINITY : '';

        const raw = cell.dataset.value !== undefined ? cell.dataset.value : cell.textContent.trim();
        if (type !== 'number') return raw.toLowerCase();

        // an empty value sorts last whichever way round it goes, it is not a zero
        const number = parseFloat(raw.replace(',', '.'));
        return Number.isNaN(number) ? Number.NEGATIVE_INFINITY : number;
    };

    const sortBy = (header, direction) => {
        const index = [...header.parentElement.children].indexOf(header);
        if (index < 0) return;

        const type = header.dataset.sort;
        const factor = direction === 'asc' ? 1 : -1;

        [...body.rows]
            .sort((a, b) => {
                const left = valueOf(a, index, type);
                const right = valueOf(b, index, type);
                if (left === right) return 0;
                return (left > right ? 1 : -1) * factor;
            })
            .forEach((row) => body.appendChild(row));

        headers.forEach((other) => {
            other.classList.remove('is-sorted-asc', 'is-sorted-desc');
            other.setAttribute('aria-sort', 'none');
        });
        header.classList.add(direction === 'asc' ? 'is-sorted-asc' : 'is-sorted-desc');
        header.setAttribute('aria-sort', direction === 'asc' ? 'ascending' : 'descending');
    };

    headers.forEach((header) => {
        header.tabIndex = 0;
        header.classList.add('hd-translator-sortable-header');

        const toggle = () => sortBy(header, header.classList.contains('is-sorted-asc') ? 'desc' : 'asc');

        header.addEventListener('click', toggle);
        header.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                toggle();
            }
        });

        // the column the server already sorted by shows that it is sorted
        if (header.dataset.sortDefault) {
            const descending = header.dataset.sortDefault !== 'asc';
            header.classList.add(descending ? 'is-sorted-desc' : 'is-sorted-asc');
            header.setAttribute('aria-sort', descending ? 'descending' : 'ascending');
        }
    });
});
