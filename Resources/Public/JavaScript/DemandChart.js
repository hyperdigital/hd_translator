/**
 * Unique visitors per language over time, drawn as SVG.
 *
 * No charting library: the module ships plain scripts with no build step, and a line chart of a
 * few hundred points is less code than the loader for one would be.
 *
 * The table on the same screen is the chart's table view, which is what lets the lighter hues
 * of the palette be used on a light surface at all.
 */
(() => {
    const root = document.getElementById('hd-translator-chart');
    if (!root) return;

    let data;
    try {
        data = JSON.parse(document.getElementById('hd-translator-chart-data').textContent);
    } catch (e) {
        return;
    }

    const languages = Object.keys(data.series || {});
    if (!languages.length || !data.days || data.days.length < 2) return;

    // Colour follows the language, never its rank: the slot comes from a stable order, so
    // hiding one series or switching the range never repaints the others.
    const SLOTS = 8;
    const slotOf = (language) => {
        const index = (data.order || languages).indexOf(language);
        return index >= 0 && index < SLOTS ? index + 1 : 0; // 0 is the neutral "other" slot
    };

    const hidden = new Set();
    const colourOf = (language) => `var(--hdt-series-${slotOf(language)})`;

    const NS = 'http://www.w3.org/2000/svg';
    const el = (name, attrs = {}) => {
        const node = document.createElementNS(NS, name);
        Object.entries(attrs).forEach(([key, value]) => node.setAttribute(key, String(value)));
        return node;
    };

    const plot = { top: 16, right: 16, bottom: 28, left: 44 };
    const svg = el('svg', { class: 'hd-translator-chart-svg', role: 'img', preserveAspectRatio: 'none' });
    root.appendChild(svg);

    const tooltip = document.createElement('div');
    tooltip.className = 'hd-translator-chart-tooltip';
    tooltip.hidden = true;
    root.appendChild(tooltip);

    const visible = () => languages.filter((language) => !hidden.has(language));

    // A round step first, then the top above the data - not a power of ten, which would round
    // 14 up to 20 and leave the upper third of the plot empty. Visitors are whole people, so the
    // step never goes below one.
    const niceStep = (value) => {
        const magnitude = 10 ** Math.floor(Math.log10(Math.max(value, 1)));
        const step = [0.2, 0.25, 0.5, 1, 2, 2.5, 5]
            .map((factor) => factor * magnitude)
            .find((candidate) => Math.max(value, 1) / candidate <= 5);

        return Math.max(Math.round(step ?? magnitude), 1);
    };

    let geometry = null;

    const draw = () => {
        const width = root.clientWidth || 720;
        const height = 260;
        svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
        svg.setAttribute('height', String(height));
        while (svg.firstChild) svg.removeChild(svg.firstChild);

        const shown = visible();
        const days = data.days;
        const peak = Math.max(1, ...shown.flatMap((language) => data.series[language]));
        const step = niceStep(peak);
        const max = Math.ceil(peak / step) * step;

        const innerWidth = Math.max(width - plot.left - plot.right, 10);
        const innerHeight = height - plot.top - plot.bottom;
        const x = (index) => plot.left + (days.length === 1 ? 0 : (index / (days.length - 1)) * innerWidth);
        const y = (value) => plot.top + innerHeight - (value / max) * innerHeight;

        // recessive grid, one line per step
        for (let value = 0; value <= max; value += step) {
            const yy = y(value);
            svg.appendChild(el('line', { x1: plot.left, x2: plot.left + innerWidth, y1: yy, y2: yy, class: 'hd-translator-chart-grid' }));
            const label = el('text', { x: plot.left - 8, y: yy + 4, class: 'hd-translator-chart-axis', 'text-anchor': 'end' });
            label.textContent = String(value);
            svg.appendChild(label);
        }

        // a date at each end and in the middle, which is as many as fits without colliding
        [0, Math.floor((days.length - 1) / 2), days.length - 1].forEach((index, position) => {
            const label = el('text', {
                x: x(index), y: height - 8, class: 'hd-translator-chart-axis',
                'text-anchor': position === 0 ? 'start' : position === 2 ? 'end' : 'middle',
            });
            label.textContent = days[index].slice(5);
            svg.appendChild(label);
        });

        shown.forEach((language) => {
            const values = data.series[language];
            const points = values.map((value, index) => `${x(index)},${y(value)}`).join(' ');
            svg.appendChild(el('polyline', { points, class: 'hd-translator-chart-line', stroke: colourOf(language) }));

            // the end carries a marker with a surface ring, and a direct label when few enough
            const last = values.length - 1;
            svg.appendChild(el('circle', { cx: x(last), cy: y(values[last]), r: 4, class: 'hd-translator-chart-end', fill: colourOf(language) }));

            if (shown.length <= 4) {
                const label = el('text', { x: x(last) - 8, y: y(values[last]) - 10, class: 'hd-translator-chart-direct', 'text-anchor': 'end' });
                label.textContent = language;
                svg.appendChild(label);
            }
        });

        const crosshair = el('line', { class: 'hd-translator-chart-crosshair', y1: plot.top, y2: plot.top + innerHeight, x1: 0, x2: 0 });
        crosshair.style.display = 'none';
        svg.appendChild(crosshair);

        geometry = { width, height, innerWidth, x, y, crosshair, shown };
    };

    /* ----------------------------------------------------------- the readout */

    const showAt = (index, clientX) => {
        if (!geometry) return;
        const { crosshair } = geometry;
        crosshair.style.display = '';
        crosshair.setAttribute('x1', String(geometry.x(index)));
        crosshair.setAttribute('x2', String(geometry.x(index)));

        while (tooltip.firstChild) tooltip.removeChild(tooltip.firstChild);

        const date = document.createElement('div');
        date.className = 'hd-translator-chart-tooltip-date';
        date.textContent = data.days[index];
        tooltip.appendChild(date);

        // every series at this x, so the pointer never has to find a line
        geometry.shown
            .map((language) => ({ language, value: data.series[language][index] }))
            .sort((a, b) => b.value - a.value)
            .forEach(({ language, value }) => {
                const line = document.createElement('div');
                line.className = 'hd-translator-chart-tooltip-row';

                const key = document.createElement('span');
                key.className = 'hd-translator-chart-key';
                key.style.background = colourOf(language);
                line.appendChild(key);

                // the value leads, the name follows: the reader has the series and wants the number
                const number = document.createElement('strong');
                number.textContent = String(value);
                line.appendChild(number);

                const name = document.createElement('span');
                name.className = 'hd-translator-chart-tooltip-name';
                name.textContent = language;
                line.appendChild(name);

                tooltip.appendChild(line);
            });

        const bounds = root.getBoundingClientRect();
        tooltip.hidden = false;
        const offset = Math.min(Math.max(clientX - bounds.left + 12, 0), bounds.width - tooltip.offsetWidth - 4);
        tooltip.style.insetInlineStart = `${Math.max(offset, 0)}px`;
    };

    const hide = () => {
        tooltip.hidden = true;
        if (geometry) geometry.crosshair.style.display = 'none';
    };

    svg.addEventListener('pointermove', (event) => {
        if (!geometry) return;
        const bounds = svg.getBoundingClientRect();
        const scale = geometry.width / bounds.width;
        const position = (event.clientX - bounds.left) * scale;
        // snap to the nearest day: the reader aims at a date, not at a 2px line
        const ratio = (position - plot.left) / geometry.innerWidth;
        const index = Math.round(Math.min(Math.max(ratio, 0), 1) * (data.days.length - 1));
        showAt(index, event.clientX);
    });
    svg.addEventListener('pointerleave', hide);

    /* ------------------------------------------------------------- the legend */

    const legend = document.getElementById('hd-translator-chart-legend');
    if (legend) {
        languages.forEach((language) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'hd-translator-chart-legend-item';
            button.setAttribute('aria-pressed', 'true');

            const key = document.createElement('span');
            key.className = 'hd-translator-chart-key';
            key.style.background = colourOf(language);
            button.appendChild(key);

            const name = document.createElement('span');
            name.textContent = language;
            button.appendChild(name);

            button.addEventListener('click', () => {
                if (hidden.has(language)) hidden.delete(language);
                else if (visible().length > 1) hidden.add(language);
                else return; // the last visible series stays, an empty chart says nothing

                button.setAttribute('aria-pressed', hidden.has(language) ? 'false' : 'true');
                button.classList.toggle('is-off', hidden.has(language));
                hide();
                draw();
            });

            legend.appendChild(button);
        });
    }

    draw();
    window.addEventListener('resize', () => { hide(); draw(); });
})();
