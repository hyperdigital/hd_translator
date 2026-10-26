async function hdtranslator_fetchSupportedLanguages() {
    try {
        const resp = await fetch('/?eID=_hdtranslator_fetchSupportedLanguages');
        if (!resp.ok) {
            console.error('Language proxy error:', await resp.text());
            return [];
        }
        const data = await resp.json();
        // data is an array of { language: "DE", name: "German" }
        return data;
    } catch (err) {
        console.error('Network error:', err);
        return [];
    }
}

async function hdtranslator_translateTextBatch(texts, targetLang) {
    const response = await fetch('/?eID=_hdtranslator_translate', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            text: texts,
            targetLang: targetLang.toUpperCase()
        })
    });
    const data = await response.json();
    if (data && data.translations && typeof data.translations === 'object') {
        // map over the original texts array to keep indices in sync:
        return texts.map(t => {
            // if there's no translation for some reason, fall back to an empty string (or t itself)
            return data.translations[t]?.text ?? false;
        });
    }
    return [];
}

async function hdtranslator_translateText(text, targetLang) {
    let batch = [];
    batch.push(text);
    const translatedTexts = await hdtranslator_translateTextBatch(batch, targetLang);
    if (translatedTexts && translatedTexts[0]) {
        return translatedTexts[0]
    } else {
        return text;
    }
}

// Server limits, see DeeplApiEid. A batch that breaks one of them is rejected as a whole,
// which would leave every string in it untranslated.
const HDTRANSLATOR_MAX_TEXTS_PER_REQUEST = 500;
const HDTRANSLATOR_MAX_TEXT_LENGTH = 5000;
const HDTRANSLATOR_MAX_BATCH_CHARS = 45000;
const HDTRANSLATOR_CONCURRENCY = 4;
const HDTRANSLATOR_HAS_LETTER = /\p{L}/u;

// What was translated before is kept in the browser, so opening a second page swaps most of it
// without asking the server at all. It expires because an editor can correct a translation in
// the backend, and nobody would see the correction until the entry aged out.
const HDTRANSLATOR_CACHE_PREFIX = 'hdtranslator.translations.';
const HDTRANSLATOR_CACHE_VERSION = 1;
const HDTRANSLATOR_CACHE_MAX_BYTES = 2 * 1024 * 1024;
const HDTRANSLATOR_CACHE_MAX_AGE = 24 * 60 * 60 * 1000;

// Content rendered after the page was translated (Alpine x-if / x-for, modals, anything loaded
// later) is picked up by an observer. It collects for a moment so a burst is one request.
const HDTRANSLATOR_OBSERVER_DEBOUNCE = 150;

const hdtranslator_state = {
    language: '',
    cache: {},
    observer: null,
    pending: new Set(),
    timer: null,
    // text node -> the value we wrote, so our own writes do not wake the observer
    written: new WeakMap(),
};

/* ------------------------------------------------------------------ cache */

function hdtranslator_loadCache(language) {
    try {
        const raw = localStorage.getItem(HDTRANSLATOR_CACHE_PREFIX + language);
        if (!raw) return {};

        const parsed = JSON.parse(raw);
        if (!parsed || parsed.v !== HDTRANSLATOR_CACHE_VERSION || !parsed.t) return {};
        if (!parsed.d || Date.now() - parsed.d > HDTRANSLATOR_CACHE_MAX_AGE) return {};

        return parsed.t;
    } catch (e) {
        // private mode, quota, corrupted entry: carry on without a cache
        return {};
    }
}

function hdtranslator_saveCache(language, translations) {
    try {
        const raw = JSON.stringify({ v: HDTRANSLATOR_CACHE_VERSION, d: Date.now(), t: translations });
        if (raw.length > HDTRANSLATOR_CACHE_MAX_BYTES) {
            localStorage.removeItem(HDTRANSLATOR_CACHE_PREFIX + language);
            return;
        }
        localStorage.setItem(HDTRANSLATOR_CACHE_PREFIX + language, raw);
    } catch (e) {
        // nothing to do, the page is translated either way
    }
}

function hdtranslator_clearTranslationCache(language) {
    try {
        if (language) {
            localStorage.removeItem(HDTRANSLATOR_CACHE_PREFIX + String(language).toUpperCase());
            return;
        }
        Object.keys(localStorage)
            .filter((key) => key.startsWith(HDTRANSLATOR_CACHE_PREFIX))
            .forEach((key) => localStorage.removeItem(key));
    } catch (e) {
        // nothing to clear
    }
}

/* ------------------------------------------------------------------ nodes */

function hdtranslator_isTranslatable(node) {
    if (!node.nodeValue || !node.nodeValue.trim()) return false;

    const parent = node.parentElement;
    if (!parent) return false;
    if (['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEMPLATE', 'TEXTAREA'].includes(parent.tagName)) return false;

    return !parent.closest('.notranslate, [data-notranslate], [translate="no"]');
}

function hdtranslator_isInViewport(element) {
    if (!element) return false;
    const box = element.getBoundingClientRect();

    return box.width > 0 && box.height > 0 && box.bottom > 0 && box.top < window.innerHeight;
}

function hdtranslator_write(entries, translated) {
    for (const { node, leading, trailing } of entries) {
        if (node.isConnected === false) continue;

        const value = leading + translated + trailing;
        hdtranslator_state.written.set(node, value);
        if (node.nodeValue !== value) node.nodeValue = value;
    }
}

/**
 * Groups the nodes by the text they hold, writes what the browser cache already knows, and
 * returns only what still has to be asked for.
 */
function hdtranslator_applyCached(nodes) {
    const groups = new Map();

    for (const node of nodes) {
        const raw = node.nodeValue;
        const text = raw.trim();

        // nothing to translate in "26", "|" or "€", and a string the server would refuse for its
        // length must not take a whole batch down with it
        if (!HDTRANSLATOR_HAS_LETTER.test(text) || text.length > HDTRANSLATOR_MAX_TEXT_LENGTH) continue;

        const entry = { node, leading: raw.match(/^\s*/)[0], trailing: raw.match(/\s*$/)[0] };
        const existing = groups.get(text);
        if (existing) existing.push(entry);
        else groups.set(text, [entry]);
    }

    const missing = new Map();
    for (const [text, entries] of groups) {
        if (Object.prototype.hasOwnProperty.call(hdtranslator_state.cache, text)) {
            hdtranslator_write(entries, hdtranslator_state.cache[text]);
        } else {
            missing.set(text, entries);
        }
    }

    return missing;
}

/**
 * Splits the texts so that no batch exceeds what the endpoint accepts.
 */
function hdtranslator_buildBatches(texts) {
    const batches = [];
    let current = [];
    let chars = 0;

    for (const text of texts) {
        if (current.length >= HDTRANSLATOR_MAX_TEXTS_PER_REQUEST || chars + text.length > HDTRANSLATOR_MAX_BATCH_CHARS) {
            batches.push(current);
            current = [];
            chars = 0;
        }
        current.push(text);
        chars += text.length;
    }
    if (current.length) batches.push(current);

    return batches;
}

async function hdtranslator_fetchMissing(missing, language) {
    if (!missing.size) return;

    // what the visitor is looking at goes first, the rest follows
    const visible = [];
    const rest = [];
    for (const [text, entries] of missing) {
        const onScreen = entries.some((entry) => entry.node.isConnected !== false && hdtranslator_isInViewport(entry.node.parentElement));
        (onScreen ? visible : rest).push(text);
    }

    const queue = hdtranslator_buildBatches(visible).concat(hdtranslator_buildBatches(rest));
    let changed = false;

    const worker = async () => {
        while (queue.length) {
            const batch = queue.shift();
            let translations;
            try {
                translations = await hdtranslator_translateTextBatch(batch, language);
            } catch (err) {
                console.warn('Translation batch failed:', err);
                continue;
            }
            if (!translations) continue;
            if (language !== hdtranslator_state.language) return; // language switched meanwhile

            batch.forEach((text, index) => {
                const translated = translations[index];
                if (!translated) return;

                hdtranslator_write(missing.get(text), translated);
                hdtranslator_state.cache[text] = translated;
                changed = true;
            });
        }
    };

    await Promise.all(Array.from({ length: Math.min(HDTRANSLATOR_CONCURRENCY, queue.length) }, worker));

    if (changed) hdtranslator_saveCache(language, hdtranslator_state.cache);
}

/* --------------------------------------------------- content rendered later */

function hdtranslator_flushPending() {
    hdtranslator_state.timer = null;

    const nodes = [...hdtranslator_state.pending].filter((node) => node.isConnected !== false && hdtranslator_isTranslatable(node));
    hdtranslator_state.pending.clear();

    if (nodes.length) hdtranslator_fetchMissing(hdtranslator_applyCached(nodes), hdtranslator_state.language);
}

function hdtranslator_onMutations(mutations) {
    const nodes = [];

    for (const mutation of mutations) {
        if (mutation.type === 'characterData') {
            if (hdtranslator_state.written.get(mutation.target) === mutation.target.nodeValue) continue;
            if (hdtranslator_isTranslatable(mutation.target)) nodes.push(mutation.target);
        } else {
            mutation.addedNodes.forEach((added) => hdtranslator_collectTextNodes(added, nodes));
        }
    }

    if (!nodes.length) return;

    // cached strings are swapped right here, before the browser paints, so nothing flashes in
    // the source language; only the unknown ones are collected and asked for once
    const missing = hdtranslator_applyCached(nodes);
    for (const entries of missing.values()) {
        entries.forEach((entry) => hdtranslator_state.pending.add(entry.node));
    }

    if (hdtranslator_state.pending.size && !hdtranslator_state.timer) {
        hdtranslator_state.timer = setTimeout(hdtranslator_flushPending, HDTRANSLATOR_OBSERVER_DEBOUNCE);
    }
}

function hdtranslator_stopTranslating() {
    if (hdtranslator_state.observer) hdtranslator_state.observer.disconnect();
    hdtranslator_state.observer = null;
    hdtranslator_state.pending.clear();
    clearTimeout(hdtranslator_state.timer);
    hdtranslator_state.timer = null;
    hdtranslator_state.language = '';
}

/* ----------------------------------------------------------------- public */

async function hdtranslator_translateWholePage(targetLang, root) {
    const language = String(targetLang || '').toUpperCase();
    if (!language) return;

    root = root || document.body;

    if (language !== hdtranslator_state.language) {
        hdtranslator_state.language = language;
        hdtranslator_state.cache = hdtranslator_loadCache(language);
    }

    // observe before translating, so nothing rendered while the requests run is missed
    if (!hdtranslator_state.observer) {
        hdtranslator_state.observer = new MutationObserver(hdtranslator_onMutations);
        hdtranslator_state.observer.observe(root, { childList: true, subtree: true, characterData: true });
    }

    await hdtranslator_fetchMissing(hdtranslator_applyCached(hdtranslator_collectTextNodes(root)), language);
}

function hdtranslator_collectTextNodes(root, target) {
    const nodes = target || [];

    // the observer hands over whatever was added, which may be a bare text node
    if (root.nodeType === Node.TEXT_NODE) {
        if (hdtranslator_isTranslatable(root)) nodes.push(root);
        return nodes;
    }
    if (root.nodeType !== Node.ELEMENT_NODE) return nodes;
    if (['SCRIPT', 'STYLE', 'NOSCRIPT', 'TEMPLATE', 'TEXTAREA'].includes(root.tagName)) return nodes;

    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
        acceptNode: (node) => (hdtranslator_isTranslatable(node) ? NodeFilter.FILTER_ACCEPT : NodeFilter.FILTER_SKIP),
    });

    while (walker.nextNode()) {
        nodes.push(walker.currentNode);
    }

    return nodes;
}
