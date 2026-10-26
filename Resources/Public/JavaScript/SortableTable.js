/**
 * Sorting for the analytics table, in the browser.
 *
 * There are at most a hundred or so languages, which fit on one page, so a round trip per sort
 * would only add waiting. A cell carries the value to sort by in data-value when what it shows
 * is not what should be compared - a percentage with a sign, an icon for yes and no.
 */
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
        const index = headers.indexOf(header) >= 0 ? [...header.parentElement.children].indexOf(header) : -1;
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

        const toggle = () => {
            const ascending = !header.classList.contains('is-sorted-asc');
            sortBy(header, ascending ? 'asc' : 'desc');
        };

        header.addEventListener('click', toggle);
        header.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                toggle();
            }
        });

        // the column the server already sorted by shows that it is sorted
        if (header.dataset.sortDefault) {
            header.classList.add(header.dataset.sortDefault === 'asc' ? 'is-sorted-asc' : 'is-sorted-desc');
            header.setAttribute('aria-sort', header.dataset.sortDefault === 'asc' ? 'ascending' : 'descending');
        }
    });
});
