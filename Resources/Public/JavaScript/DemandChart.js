/**
 * The charts of the demand screen, drawn as SVG.
 *
 * No charting library: the module ships plain scripts with no build step, the backend's CSP does
 * not admit a chart bundle from a CDN, and the whole file is still smaller than the loader of one
 * would be.
 *
 * Four things are drawn here - the daily chart, the monthly history, a sparkline per table row and
 * the CSV of the table - and each one guards its own root, so a screen that is missing one of them
 * still gets the others.
 *
 * The table on the same screen is the chart's table view, which is what lets the lighter hues of
 * the palette be used on a light surface at all, and what makes the chart readable without a
 * mouse: every number in it is in the table too.
 */
(() => {
    'use strict';

    const NS = 'http://www.w3.org/2000/svg';
    const el = (name, attrs = {}) => {
        const node = document.createElementNS(NS, name);
        for (const [key, value] of Object.entries(attrs)) node.setAttribute(key, String(value));
        return node;
    };
    const clear = (node) => { while (node.firstChild) node.removeChild(node.firstChild); };

    // the backend's own locale, so thousands are grouped the way the rest of the module groups them
    const locale = document.documentElement.lang || undefined;
    const num = new Intl.NumberFormat(locale);

    const read = (id) => {
        const node = document.getElementById(id);
        if (!node) return null;
        try {
            return JSON.parse(node.textContent);
        } catch (e) {
            return null;
        }
    };

    /**
     * A round step first, then the top above the data - not a power of ten, which would round 14
     * up to 20 and leave the upper third of the plot empty. Visitors are whole people, so the step
     * never goes below one.
     */
    const niceStep = (value) => {
        const magnitude = 10 ** Math.floor(Math.log10(Math.max(value, 1)));
        const step = [0.2, 0.25, 0.5, 1, 2, 2.5, 5]
            .map((factor) => factor * magnitude)
            .find((candidate) => Math.max(value, 1) / candidate <= 5);

        return Math.max(Math.round(step ?? magnitude), 1);
    };

    /** A tooltip that lives in the plot and never catches the pointer. */
    const makeTooltip = (host) => {
        const node = document.createElement('div');
        node.className = 'hd-translator-chart-tooltip';
        node.hidden = true;
        host.appendChild(node);

        return {
            node,
            show(clientX) {
                const bounds = host.getBoundingClientRect();
                node.hidden = false;
                const wanted = clientX - bounds.left + 12;
                const last = bounds.width - node.offsetWidth - 4;
                node.style.insetInlineStart = `${Math.max(0, Math.min(wanted, Math.max(last, 0)))}px`;
            },
            hide() { node.hidden = true; },
        };
    };

    const row = (colour, value, name, classes = '', block = false) => {
        const line = document.createElement('div');
        line.className = `hd-translator-chart-tooltip-row${classes ? ' ' + classes : ''}`;

        // a line key for a line, a block for an area or a bar: the key is a sample of the mark
        const key = document.createElement('span');
        key.className = `hd-translator-chart-key${block ? ' is-block' : ''}`;
        if (colour) key.style.background = colour;
        line.appendChild(key);

        // the value leads, the name follows: the reader has the series and wants the number
        const number = document.createElement('strong');
        number.textContent = value;
        line.appendChild(number);

        const label = document.createElement('span');
        label.className = 'hd-translator-chart-tooltip-name';
        label.textContent = name;
        line.appendChild(label);

        return line;
    };

    /**
     * Colour follows the language, never its rank: the slot comes from a stable order, so hiding
     * one series or switching the range never repaints the others.
     */
    const SLOTS = 8;
    const palette = (order) => (language) => {
        const index = order.indexOf(language);
        const slot = index >= 0 && index < SLOTS ? index + 1 : 0; // 0 is the neutral "other" slot
        return `var(--hdt-series-${slot})`;
    };

    /* ====================================================================== the daily chart */

    const daily = (() => {
        const root = document.getElementById('hd-translator-chart');
        const data = read('hd-translator-chart-data');
        if (!root || !data) return null;

        const languages = Object.keys(data.series || {});
        if (!languages.length || !data.days || data.days.length < 2) return null;

        const colourOf = palette(data.order || languages);
        const text = root.dataset;

        const svg = el('svg', {
            class: 'hd-translator-chart-svg',
            role: 'img',
            tabindex: '0',
            preserveAspectRatio: 'none',
        });
        root.appendChild(svg);
        const tooltip = makeTooltip(root);

        const plot = { top: 22, right: 18, bottom: 28, left: 46 };
        const state = {
            mode: 'lines',
            smooth: false,
            hidden: new Set(),
            spot: null,
            // the overlay needs a language even when the pointer is elsewhere, so it keeps one
            // of its own; hovering still wins
            pin: null,
            overlay: false,
            from: 0,
            to: data.days.length - 1,
            read: null,
            drag: null,
        };
        let geometry = null;

        const visible = () => languages.filter((language) => !state.hidden.has(language));

        /**
         * The language the overlay talks about: the one being pointed at, the one it pinned when
         * it was switched on, or the only one left visible.
         */
        const focused = () => {
            const shown = visible();
            if (state.spot && shown.includes(state.spot)) return state.spot;
            if (shown.length === 1) return shown[0];
            if (state.pin && shown.includes(state.pin)) return state.pin;
            return null;
        };

        const split = (language) => (data.banner || {})[language] || null;

        /** Whether the split can be drawn at all: lines mode, switched on, and a language to show. */
        const overlayOf = () => {
            if (!state.overlay || state.mode !== 'lines') return null;
            const language = focused();
            if (!language) return null;
            const parts = split(language);
            return parts ? { language, parts } : null;
        };

        /**
         * The runs of days the banner reported on. A day it said nothing about is not a day with
         * nought visitors, and drawing the two the same way would turn "nobody measured" into
         * "the banner brought nobody".
         */
        const knownRuns = (known, first, last) => {
            const runs = [];
            let start = null;
            for (let index = first; index <= last; index++) {
                if (known[index]) {
                    if (start === null) start = index;
                } else if (start !== null) {
                    runs.push([start, index - 1]);
                    start = null;
                }
            }
            if (start !== null) runs.push([start, last]);

            return runs;
        };

        /**
         * A centred seven day mean. Over ninety days and more the daily noise is louder than the
         * trend the reader came for, and the raw series stays one click away.
         */
        const valuesOf = (language) => {
            const values = data.series[language];
            if (!state.smooth) return values;

            return values.map((value, index) => {
                let sum = 0;
                let counted = 0;
                for (let offset = -3; offset <= 3; offset++) {
                    const at = index + offset;
                    if (at >= 0 && at < values.length) { sum += values[at]; counted++; }
                }
                return sum / counted;
            });
        };

        /**
         * A filled band between two value functions over a run of days. A run of one day has no
         * width to fill, so it gets a sliver instead of nothing.
         */
        const band = (from, to, top, bottom, x, y) => {
            if (from === to) {
                const half = Math.max((x(1) - x(0)) / 2, 1);
                return `M ${x(from) - half},${y(bottom(from))} L ${x(from) - half},${y(top(from))}`
                    + ` L ${x(from) + half},${y(top(from))} L ${x(from) + half},${y(bottom(from))} Z`;
            }

            const upper = [];
            const lower = [];
            for (let index = from; index <= to; index++) {
                upper.push(`${x(index)},${y(top(index))}`);
                lower.push(`${x(index)},${y(bottom(index))}`);
            }

            return `M ${upper.join(' L ')} L ${lower.reverse().join(' L ')} Z`;
        };

        const draw = () => {
            const overlay = overlayOf();
            const width = Math.max(root.clientWidth || 720, 240);
            // the split hangs a second half below the axis, and squeezing both into the height
            // of one leaves the lines flat enough to say nothing
            const height = overlay ? 380 : 280;
            svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
            svg.setAttribute('height', String(height));
            clear(svg);

            const shown = visible();
            const first = state.from;
            const last = state.to;
            const span = Math.max(last - first, 1);
            const innerWidth = Math.max(width - plot.left - plot.right, 10);
            const innerHeight = height - plot.top - plot.bottom;
            const x = (index) => plot.left + ((index - first) / span) * innerWidth;

            // the totals of the window, which both stacked modes are built on
            const totals = [];
            for (let index = first; index <= last; index++) {
                totals.push(shown.reduce((sum, language) => sum + valuesOf(language)[index], 0));
            }

            // the lost half hangs below the axis, so the domain reaches into the negative and
            // both halves keep one step, one scale and therefore comparable heights
            let floor = 0;
            if (overlay) {
                for (let index = first; index <= last; index++) {
                    if (overlay.parts.known[index]) floor = Math.max(floor, overlay.parts.lost[index]);
                }
            }

            let max;
            let step;
            let axisLabel;
            if (state.mode === 'share') {
                max = 100;
                step = 25;
                axisLabel = (value) => `${value} %`;
            } else {
                const peak = state.mode === 'stacked'
                    ? Math.max(1, ...totals)
                    : Math.max(1, ...shown.flatMap((language) => valuesOf(language).slice(first, last + 1)));
                step = niceStep(peak);
                max = Math.ceil(peak / step) * step;
                axisLabel = (value) => num.format(value);
            }

            const bottom = floor > 0 ? Math.ceil(floor / step) * step : 0;
            const domain = max + bottom;
            const y = (value) => plot.top + ((max - value) / domain) * innerHeight;

            // recessive grid, one line per step, in both directions where there is a below
            for (let value = -bottom; value <= max + 1e-9; value += step) {
                svg.appendChild(el('line', {
                    x1: plot.left, x2: plot.left + innerWidth, y1: y(value), y2: y(value),
                    class: 'hd-translator-chart-grid' + (value === 0 && bottom > 0 ? ' is-zero' : ''),
                }));
                const label = el('text', {
                    x: plot.left - 8, y: y(value) + 4,
                    class: 'hd-translator-chart-axis', 'text-anchor': 'end',
                });
                // the half below the axis counts people too, so it is labelled without the sign
                label.textContent = axisLabel(Math.abs(value));
                svg.appendChild(label);
            }

            // about five dates, which is as many as fits without colliding
            const ticks = Math.min(5, span + 1);
            for (let tick = 0; tick < ticks; tick++) {
                const index = Math.round(first + (span * tick) / Math.max(ticks - 1, 1));
                const label = el('text', {
                    x: x(index), y: height - 8, class: 'hd-translator-chart-axis',
                    'text-anchor': tick === 0 ? 'start' : tick === ticks - 1 ? 'end' : 'middle',
                });
                label.textContent = data.days[index].slice(5);
                svg.appendChild(label);
            }

            const dimmed = (language) => state.spot && state.spot !== language;

            if (state.mode === 'lines') {
                shown.forEach((language) => {
                    const values = valuesOf(language);
                    const points = [];
                    for (let index = first; index <= last; index++) points.push(`${x(index)},${y(values[index])}`);

                    // one language alone gets an area: at that point the fill is the data, not ink
                    if (state.spot === language || shown.length === 1) {
                        svg.appendChild(el('path', {
                            d: `M ${x(first)},${y(0)} L ${points.join(' L ')} L ${x(last)},${y(0)} Z`,
                            class: 'hd-translator-chart-area', fill: colourOf(language), 'fill-opacity': .14,
                        }));
                    }

                    // how this language got its visitors, drawn inside the area it already has:
                    // the part the banner brought is a subset of the line by construction, so it
                    // can never overflow it
                    if (overlay && overlay.language === language) {
                        const parts = overlay.parts;

                        knownRuns(parts.known, first, last).forEach(([from, to]) => {
                            svg.appendChild(el('path', {
                                d: band(from, to, (index) => parts.banner[index], () => 0, x, y),
                                class: 'hd-translator-chart-area', fill: colourOf(language), 'fill-opacity': .45,
                            }));

                            // the ones it was offered to for nothing are not in the line at all,
                            // so they hang below the axis instead of being stacked on top of it
                            svg.appendChild(el('path', {
                                d: band(from, to, () => 0, (index) => -parts.lost[index], x, y),
                                class: 'hd-translator-chart-area hd-translator-chart-lost',
                            }));
                        });
                    }

                    svg.appendChild(el('polyline', {
                        points: points.join(' '),
                        class: 'hd-translator-chart-line'
                            + (dimmed(language) ? ' is-dim' : '')
                            + (state.spot === language ? ' is-lift' : ''),
                        stroke: colourOf(language),
                    }));

                    // the end carries a marker with a surface ring, and a direct label when few enough
                    svg.appendChild(el('circle', {
                        cx: x(last), cy: y(values[last]), r: 4,
                        class: 'hd-translator-chart-end' + (dimmed(language) ? ' is-dim' : ''),
                        fill: colourOf(language),
                    }));

                    if (shown.length <= 4 || state.spot === language) {
                        const label = el('text', {
                            x: x(last) - 8, y: y(values[last]) - 10,
                            class: 'hd-translator-chart-direct' + (dimmed(language) ? ' is-dim' : ''),
                            'text-anchor': 'end',
                        });
                        label.textContent = language;
                        svg.appendChild(label);
                    }
                });

                // the busiest day of the window, named, so "when was it busiest" needs no squinting
                if (state.read === null && !state.drag && shown.length) {
                    let best = { value: -1, index: first, language: shown[0] };
                    shown.forEach((language) => {
                        const values = valuesOf(language);
                        for (let index = first; index <= last; index++) {
                            if (values[index] > best.value) best = { value: values[index], index, language };
                        }
                    });

                    if (best.value > 0) {
                        const label = el('text', {
                            x: Math.min(Math.max(x(best.index), plot.left + 4), plot.left + innerWidth - 4),
                            y: Math.max(y(best.value) - 14, 12),
                            class: 'hd-translator-chart-peak',
                            'text-anchor': best.index > (first + last) / 2 ? 'end' : 'start',
                        });
                        label.textContent = [
                            text.labelPeak || 'peak',
                            num.format(Math.round(best.value)),
                            best.language,
                            data.days[best.index].slice(5),
                        ].join(' · ');
                        svg.appendChild(label);
                    }
                }
            } else {
                // stacked bottom to top in palette order, so the bands never swap places
                const order = (data.order || languages).filter((language) => shown.includes(language));
                const below = new Array(data.days.length).fill(0);

                order.forEach((language) => {
                    const values = valuesOf(language);
                    const top = [];
                    const bottom = [];
                    for (let index = first; index <= last; index++) {
                        const total = totals[index - first] || 1;
                        const unit = state.mode === 'share' ? 100 / total : 1;
                        bottom.push(`${x(index)},${y(below[index] * unit)}`);
                        top.push(`${x(index)},${y((below[index] + values[index]) * unit)}`);
                    }

                    svg.appendChild(el('path', {
                        d: `M ${top.join(' L ')} L ${bottom.reverse().join(' L ')} Z`,
                        class: 'hd-translator-chart-area' + (dimmed(language) ? ' is-dim' : ''),
                        fill: colourOf(language),
                        'fill-opacity': state.spot === language ? .95 : .72,
                    }));

                    for (let index = first; index <= last; index++) below[index] += values[index];
                });
            }

            const crosshair = el('line', {
                class: 'hd-translator-chart-crosshair',
                x1: 0, x2: 0, y1: plot.top, y2: plot.top + innerHeight,
            });
            void domain;
            crosshair.style.display = 'none';
            svg.appendChild(crosshair);

            const dots = el('g');
            svg.appendChild(dots);

            const brush = el('rect', {
                class: 'hd-translator-chart-brush', x: 0, y: plot.top, width: 0, height: innerHeight, rx: 2,
            });
            brush.style.display = 'none';
            svg.appendChild(brush);

            svg.setAttribute('aria-label', [
                text.labelChart || 'Unique visitors per day',
                shown.join(', '),
                `${data.days[first]} – ${data.days[last]}`,
            ].join('. '));

            geometry = { width, innerWidth, x, y, crosshair, dots, brush, shown, first, last, totals, overlay };
            if (state.read !== null) readout(state.read, null);
            if (state.drag) paintBrush();
        };

        /* --------------------------------------------------------------------- the readout */

        const readout = (index, clientX) => {
            if (!geometry) return;
            state.read = index;

            geometry.crosshair.style.display = '';
            geometry.crosshair.setAttribute('x1', String(geometry.x(index)));
            geometry.crosshair.setAttribute('x2', String(geometry.x(index)));

            clear(geometry.dots);
            clear(tooltip.node);

            const date = document.createElement('div');
            date.className = 'hd-translator-chart-tooltip-date';
            date.textContent = data.days[index];
            tooltip.node.appendChild(date);

            const total = geometry.shown.reduce((sum, language) => sum + valuesOf(language)[index], 0);
            const ordered = geometry.shown
                .map((language) => ({ language, value: valuesOf(language)[index] }))
                .sort((a, b) => b.value - a.value);

            // a dot per series where it is being read, so the eye never has to find the line
            if (state.mode === 'lines') {
                ordered.forEach(({ language, value }) => {
                    geometry.dots.appendChild(el('circle', {
                        cx: geometry.x(index), cy: geometry.y(value), r: 3.5,
                        class: 'hd-translator-chart-dot', fill: colourOf(language),
                    }));
                });
            } else {
                let below = 0;
                (data.order || languages)
                    .filter((language) => geometry.shown.includes(language))
                    .forEach((language) => {
                        const unit = state.mode === 'share' ? 100 / (total || 1) : 1;
                        below += valuesOf(language)[index];
                        geometry.dots.appendChild(el('circle', {
                            cx: geometry.x(index), cy: geometry.y(below * unit), r: 3,
                            class: 'hd-translator-chart-dot', fill: colourOf(language),
                        }));
                    });
            }

            // every series at this x, so the pointer never has to find a line
            ordered.forEach(({ language, value }) => {
                const value_ = state.mode === 'share'
                    ? `${Math.round((value / (total || 1)) * 100)} %`
                    : num.format(Math.round(value));
                tooltip.node.appendChild(
                    row(colourOf(language), value_, language, state.spot === language ? 'is-hot' : '', state.mode !== 'lines')
                );
            });

            // how the focused language got that day, when the split is being drawn
            if (geometry.overlay) {
                const parts = geometry.overlay.parts;
                const language = geometry.overlay.language;

                if (parts.known[index]) {
                    const asked = valuesOf(language)[index];
                    tooltip.node.appendChild(row(
                        colourOf(language), num.format(parts.banner[index]),
                        text.labelVia || 'via the banner', 'hd-translator-chart-tooltip-total'
                    ));
                    tooltip.node.appendChild(row(
                        null, num.format(Math.max(Math.round(asked) - parts.banner[index], 0)),
                        text.labelOther || 'other ways'
                    ));
                    tooltip.node.appendChild(row(
                        null, num.format(parts.lost[index]), text.labelLost || 'offered, not used'
                    ));
                } else {
                    tooltip.node.appendChild(row(
                        null, '\u2013', text.labelUnknown || 'nothing reported', 'hd-translator-chart-tooltip-total'
                    ));
                }
            }

            // the total, which is the one number the chart itself cannot show in "lines"
            if (state.mode !== 'share' && geometry.shown.length > 1) {
                tooltip.node.appendChild(
                    row(null, num.format(Math.round(total)), text.labelTotal || 'total', 'hd-translator-chart-tooltip-total')
                );
            }

            const bounds = svg.getBoundingClientRect();
            tooltip.show(clientX ?? bounds.left + (geometry.x(index) / geometry.width) * bounds.width);
        };

        const hide = () => {
            state.read = null;
            tooltip.hide();
            if (geometry) {
                geometry.crosshair.style.display = 'none';
                clear(geometry.dots);
            }
        };

        const indexAt = (clientX) => {
            const bounds = svg.getBoundingClientRect();
            const scale = geometry.width / bounds.width;
            const position = (clientX - bounds.left) * scale;
            // snap to the nearest day: the reader aims at a date, not at a 2px line
            const ratio = (position - plot.left) / geometry.innerWidth;
            const span = geometry.last - geometry.first;
            return Math.round(geometry.first + Math.min(Math.max(ratio, 0), 1) * span);
        };

        const paintBrush = () => {
            if (!geometry || !state.drag) return;
            const from = geometry.x(state.drag.from);
            const to = geometry.x(state.drag.to);
            geometry.brush.style.display = '';
            geometry.brush.setAttribute('x', String(Math.min(from, to)));
            geometry.brush.setAttribute('width', String(Math.abs(to - from)));
        };

        /* -------------------------------------------------------------------- the pointer */

        svg.addEventListener('pointermove', (event) => {
            if (!geometry) return;
            const index = indexAt(event.clientX);
            if (state.drag) {
                state.drag.to = index;
                paintBrush();
                tooltip.hide();
                return;
            }
            readout(index, event.clientX);
        });

        svg.addEventListener('pointerleave', () => { if (!state.drag) hide(); });

        svg.addEventListener('pointerdown', (event) => {
            if (!geometry || event.button !== 0) return;
            const index = indexAt(event.clientX);
            state.drag = { from: index, to: index };
            try { svg.setPointerCapture(event.pointerId); } catch (e) { /* a mouse without capture */ }

            // without this the browser starts selecting the axis labels, the drag turns into a
            // text selection and the release never reaches the chart
            event.preventDefault();
        });

        const endDrag = (event) => {
            if (!state.drag) return;
            const { from, to } = state.drag;
            state.drag = null;
            if (geometry) geometry.brush.style.display = 'none';

            // a drag of one day is a click, and a click is a readout, not a zoom to nothing
            if (Math.abs(to - from) >= 2) {
                state.from = Math.min(from, to);
                state.to = Math.max(from, to);
                hide();
                zoomed(true);
                draw();
            } else if (event) {
                readout(indexAt(event.clientX), event.clientX);
            }
        };
        svg.addEventListener('pointerup', endDrag);
        svg.addEventListener('pointercancel', () => endDrag(null));
        // a release outside the plot is the normal way to end a drag, and it never reaches the svg
        window.addEventListener('pointerup', endDrag);

        /* ------------------------------------------------------------------- the keyboard */

        svg.addEventListener('keydown', (event) => {
            if (!geometry) return;

            if (event.key === 'ArrowRight' || event.key === 'ArrowLeft') {
                const at = state.read === null ? state.to : state.read;
                const next = at + (event.key === 'ArrowRight' ? 1 : -1);
                readout(Math.min(Math.max(next, state.from), state.to), null);
                event.preventDefault();
            } else if (event.key === 'Home') {
                readout(state.from, null);
                event.preventDefault();
            } else if (event.key === 'End') {
                readout(state.to, null);
                event.preventDefault();
            } else if (event.key === 'Escape') {
                hide();
            }
        });

        /* -------------------------------------------------------------------- the controls */

        const marked = (button, on) => {
            button.setAttribute('aria-pressed', on ? 'true' : 'false');
            button.classList.toggle('btn-primary', on);
            button.classList.toggle('btn-default', !on);
        };

        const unzoom = document.querySelector('[data-chart-unzoom]');
        const zoomed = (on) => { if (unzoom) unzoom.hidden = !on; };
        zoomed(false);

        if (unzoom) {
            unzoom.addEventListener('click', () => {
                state.from = 0;
                state.to = data.days.length - 1;
                zoomed(false);
                hide();
                draw();
            });
        }

        const modes = [...document.querySelectorAll('[data-chart-mode]')];
        modes.forEach((button) => {
            marked(button, button.dataset.chartMode === state.mode);
            button.addEventListener('click', () => {
                state.mode = button.dataset.chartMode;
                modes.forEach((other) => marked(other, other === button));
                // the fill means something else in the stacked modes, so the split stays out
                onlyInLines();
                hide();
                draw();
            });
        });

        const overlayButton = document.querySelector('[data-chart-banner]');
        if (overlayButton) {
            marked(overlayButton, false);

            overlayButton.addEventListener('click', () => {
                state.overlay = !state.overlay;
                marked(overlayButton, state.overlay);

                // switched on with nothing pointed at, it pins the language that has the most to
                // say, so the first thing the reader sees is the answer rather than an invitation
                if (state.overlay && !focused()) {
                    let best = null;
                    let most = -1;
                    visible().forEach((language) => {
                        const parts = split(language);
                        if (!parts) return;
                        let sum = 0;
                        for (let index = state.from; index <= state.to; index++) {
                            sum += parts.banner[index] + parts.lost[index];
                        }
                        if (sum > most) { most = sum; best = language; }
                    });
                    state.pin = best;
                } else if (!state.overlay) {
                    state.pin = null;
                }

                hide();
                draw();
            });
        }

        const onlyInLines = () => {
            if (!overlayButton) return;
            overlayButton.disabled = state.mode !== 'lines';
            overlayButton.setAttribute('aria-disabled', state.mode !== 'lines' ? 'true' : 'false');
        };

        const smooth = document.querySelector('[data-chart-smooth]');
        if (smooth) {
            marked(smooth, false);
            smooth.addEventListener('click', () => {
                state.smooth = !state.smooth;
                marked(smooth, state.smooth);
                hide();
                draw();
            });
        }

        /* ---------------------------------------------------------------------- the legend */

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
                    if (state.hidden.has(language)) state.hidden.delete(language);
                    else if (visible().length > 1) state.hidden.add(language);
                    else return; // the last visible series stays, an empty chart says nothing

                    const off = state.hidden.has(language);
                    button.setAttribute('aria-pressed', off ? 'false' : 'true');
                    button.classList.toggle('is-off', off);
                    hide();
                    draw();
                });

                // six lines across each other cannot be told apart; pointing at one lifts it
                const spotlight = (on) => {
                    state.spot = on && !state.hidden.has(language) ? language : null;
                    draw();
                };
                button.addEventListener('pointerenter', () => spotlight(true));
                button.addEventListener('pointerleave', () => spotlight(false));
                button.addEventListener('focus', () => spotlight(true));
                button.addEventListener('blur', () => spotlight(false));

                legend.appendChild(button);
            });
        }

        if (window.ResizeObserver) {
            new ResizeObserver(() => { hide(); draw(); }).observe(root);
        } else {
            window.addEventListener('resize', () => { hide(); draw(); });
        }

        onlyInLines();
        draw();

        return {
            series: data.series,
            days: data.days,
            colourOf,
            spotlight(language) {
                state.spot = language && !state.hidden.has(language) ? language : null;
                draw();
            },
        };
    })();

    /* ================================================================= the monthly history */

    (() => {
        const root = document.getElementById('hd-translator-history');
        const data = read('hd-translator-history-data');
        if (!root || !data || !data.months || !data.months.length) return;

        const languages = Object.keys(data.series || {});
        if (!languages.length) return;

        const order = (data.order || languages).filter((language) => languages.includes(language));
        const colourOf = palette(data.order || languages);
        const text = root.dataset;

        const svg = el('svg', { class: 'hd-translator-chart-svg', role: 'img', preserveAspectRatio: 'none' });
        root.appendChild(svg);
        const tooltip = makeTooltip(root);

        const month = (value) => {
            const raw = String(value);
            return `${raw.slice(4, 6)}/${raw.slice(2, 4)}`;
        };

        const totals = data.months.map((_, index) => order.reduce((sum, language) => sum + data.series[language][index], 0));
        const peak = Math.max(1, ...totals);
        const step = niceStep(peak);
        const max = Math.ceil(peak / step) * step;

        const plot = { top: 14, right: 10, bottom: 28, left: 46 };

        const draw = () => {
            const width = Math.max(root.clientWidth || 720, 240);
            const height = 200;
            svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
            svg.setAttribute('height', String(height));
            clear(svg);

            const innerWidth = Math.max(width - plot.left - plot.right, 10);
            const innerHeight = height - plot.top - plot.bottom;
            const slot = innerWidth / data.months.length;
            const y = (value) => plot.top + innerHeight - (value / max) * innerHeight;

            for (let value = 0; value <= max + 1e-9; value += step) {
                svg.appendChild(el('line', {
                    x1: plot.left, x2: plot.left + innerWidth, y1: y(value), y2: y(value),
                    class: 'hd-translator-chart-grid',
                }));
                const label = el('text', {
                    x: plot.left - 8, y: y(value) + 4, class: 'hd-translator-chart-axis', 'text-anchor': 'end',
                });
                label.textContent = num.format(value);
                svg.appendChild(label);
            }

            // every third month is labelled, plus the last one, which is the one being compared to
            data.months.forEach((value, index) => {
                const x = plot.left + index * slot + slot * 0.15;
                const barWidth = Math.max(slot * 0.7, 1);
                let below = 0;

                const group = el('g', {
                    tabindex: '0',
                    role: 'img',
                    'aria-label': `${month(value)}: ${num.format(totals[index])}`,
                });

                order.forEach((language) => {
                    const amount = data.series[language][index];
                    group.appendChild(el('rect', {
                        x, y: y(below + amount), width: barWidth,
                        height: Math.max((amount / max) * innerHeight, amount > 0 ? 0.5 : 0),
                        class: 'hd-translator-chart-bar', fill: colourOf(language), 'fill-opacity': .85,
                    }));
                    below += amount;
                });

                const show = (event) => {
                    clear(tooltip.node);

                    const head = document.createElement('div');
                    head.className = 'hd-translator-chart-tooltip-date';
                    head.textContent = month(value);
                    tooltip.node.appendChild(head);

                    [...order]
                        .sort((a, b) => data.series[b][index] - data.series[a][index])
                        .filter((language) => data.series[language][index] > 0)
                        .forEach((language) => {
                            tooltip.node.appendChild(
                                row(colourOf(language), num.format(data.series[language][index]), language, '', true)
                            );
                        });

                    tooltip.node.appendChild(
                        row(null, num.format(totals[index]), text.labelTotal || 'total', 'hd-translator-chart-tooltip-total')
                    );

                    const bounds = svg.getBoundingClientRect();
                    tooltip.show(event
                        ? event.clientX
                        : bounds.left + ((x + barWidth / 2) / width) * bounds.width);
                };

                group.addEventListener('pointerenter', show);
                group.addEventListener('focus', () => show(null));
                group.addEventListener('pointerleave', () => tooltip.hide());
                group.addEventListener('blur', () => tooltip.hide());
                svg.appendChild(group);

                const every = Math.max(1, Math.round(data.months.length / 8));
                if (index % every === 0 || index === data.months.length - 1) {
                    const label = el('text', {
                        x: x + barWidth / 2, y: height - 10,
                        class: 'hd-translator-chart-axis', 'text-anchor': 'middle',
                    });
                    label.textContent = month(value);
                    svg.appendChild(label);
                }
            });

            svg.setAttribute('aria-label', text.labelChart || 'Visitor days per month');
        };

        if (window.ResizeObserver) new ResizeObserver(draw).observe(root);
        else window.addEventListener('resize', draw);

        draw();
    })();

    /* ====================================================================== the sparklines */

    (() => {
        if (!daily) return;

        const cells = document.querySelectorAll('[data-hdt-spark]');
        if (!cells.length) return;

        cells.forEach((cell) => {
            const language = cell.dataset.hdtSpark;
            const values = daily.series[language];
            if (!values || values.length < 2) return;

            const width = 92;
            const height = 24;
            const max = Math.max(1, ...values);
            const svg = el('svg', {
                class: 'hd-translator-spark', viewBox: `0 0 ${width} ${height}`,
                'aria-hidden': 'true', focusable: 'false', preserveAspectRatio: 'none',
            });

            const points = values.map((value, index) => {
                const x = (index / (values.length - 1)) * (width - 2) + 1;
                return `${x},${height - 2 - (value / max) * (height - 4)}`;
            });

            svg.appendChild(el('path', {
                d: `M 1,${height - 1} L ${points.join(' L ')} L ${width - 1},${height - 1} Z`,
                fill: daily.colourOf(language), 'fill-opacity': .13, stroke: 'none',
            }));
            svg.appendChild(el('polyline', {
                points: points.join(' '), fill: 'none', stroke: daily.colourOf(language),
                'stroke-width': 1.5, 'stroke-linejoin': 'round',
            }));

            const [lastX, lastY] = points[points.length - 1].split(',');
            svg.appendChild(el('circle', { cx: lastX, cy: lastY, r: 2, fill: daily.colourOf(language) }));

            cell.appendChild(svg);
        });

        // the table is the chart's legend: pointing at a row lifts its line in the chart above
        document.querySelectorAll('[data-hdt-language]').forEach((node) => {
            const language = node.dataset.hdtLanguage;
            if (!daily.series[language]) return;

            node.addEventListener('pointerenter', () => daily.spotlight(language));
            node.addEventListener('pointerleave', () => daily.spotlight(null));
            node.addEventListener('focusin', () => daily.spotlight(language));
            node.addEventListener('focusout', () => daily.spotlight(null));
        });
    })();

    /* ============================================================================= the CSV */

    (() => {
        const button = document.querySelector('[data-hdt-csv]');
        const table = document.querySelector('.hd-translator-sortable');
        if (!button || !table) return;

        // read out of the table, not out of the data: what is copied is then exactly what is read
        const csv = () => {
            const cell = (node) => {
                const value = (node.dataset.value ?? node.textContent).trim().replace(/\s+/g, ' ');
                return /[",;\n]/.test(value) ? `"${value.replace(/"/g, '""')}"` : value;
            };

            return [...table.querySelectorAll('tr')]
                .map((line) => [...line.children]
                    .filter((node) => !node.hasAttribute('data-hdt-spark'))
                    .map(cell)
                    .join(';'))
                .join('\n');
        };

        const say = (message) => {
            const note = button.querySelector('[data-hdt-csv-state]') || button;
            const original = button.dataset.hdtCsvLabel || button.textContent.trim();
            button.dataset.hdtCsvLabel = original;
            note.textContent = message;
            window.setTimeout(() => { note.textContent = original; }, 2500);
        };

        button.addEventListener('click', async () => {
            const text = csv();
            try {
                await navigator.clipboard.writeText(text);
                say(button.dataset.labelCopied || 'Copied');
            } catch (e) {
                // an older backend or a denied permission: select it instead, so ctrl+c still works
                const area = document.createElement('textarea');
                area.value = text;
                area.setAttribute('readonly', 'readonly');
                area.className = 'hd-translator-csv-fallback';
                table.parentNode.insertBefore(area, table);
                area.select();
                say(button.dataset.labelCopyFailed || 'Press ctrl+c');
            }
        });
    })();
})();
