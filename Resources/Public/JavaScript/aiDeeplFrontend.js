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

async function hdtranslator_translateWholePage(targetLang) {
    // 1) Collect the nodes and group them by the text they hold. A label that appears in the
    //    menu and again in the footer is one string to translate, not two.
    const groups = new Map(); // text -> [{node, leading, trailing}]

    for (const node of hdtranslator_collectTextNodes(document.body)) {
        const raw = node.nodeValue;
        const coreText = raw.trim();

        // nothing to translate in "26", "|" or "€", and a string the server would refuse
        // for its length must not take a whole batch down with it
        if (!HDTRANSLATOR_HAS_LETTER.test(coreText) || coreText.length > HDTRANSLATOR_MAX_TEXT_LENGTH) {
            continue;
        }

        const entry = { node, leading: raw.match(/^\s*/)[0], trailing: raw.match(/\s*$/)[0] };
        const existing = groups.get(coreText);
        if (existing) existing.push(entry);
        else groups.set(coreText, [entry]);
    }

    if (!groups.size) return;

    // 2) Request the batches a few at a time instead of strictly one after another
    const queue = hdtranslator_buildBatches([...groups.keys()]);

    const worker = async () => {
        while (queue.length) {
            const batch = queue.shift();
            let translations;
            try {
                translations = await hdtranslator_translateTextBatch(batch, targetLang);
            } catch (err) {
                console.warn('Translation batch failed:', err);
                continue;
            }
            if (!translations) continue;

            // 3) Write the result back, whitespace restored, to every node holding that text
            batch.forEach((text, idx) => {
                const translated = translations[idx];
                if (!translated) return;

                for (const { node, leading, trailing } of groups.get(text)) {
                    if (node.isConnected !== false) {
                        node.nodeValue = leading + translated + trailing;
                    }
                }
            });
        }
    };

    await Promise.all(
        Array.from({ length: Math.min(HDTRANSLATOR_CONCURRENCY, queue.length) }, worker)
    );
}

function hdtranslator_collectTextNodes(root) {
    const walker = document.createTreeWalker(
        root,
        NodeFilter.SHOW_TEXT,
        {
            acceptNode: (node) => {
                // skip empty text
                if (!node.nodeValue.trim()) return NodeFilter.FILTER_SKIP;

                // skip script/style/etc.
                const parentTag = node.parentElement?.tagName?.toLowerCase();
                if (['script','style','noscript'].includes(parentTag)) {
                    return NodeFilter.FILTER_SKIP;
                }

                // if this node is inside any .notranslate or [data-notranslate] or translate="no", skip:
                if (node.parentElement.closest(
                    '.notranslate, [data-notranslate], [translate="no"]'
                )) {
                    return NodeFilter.FILTER_SKIP;
                }

                return NodeFilter.FILTER_ACCEPT;
            }
        }
    );

    const nodes = [];
    while (walker.nextNode()) {
        nodes.push(walker.currentNode);
    }
    return nodes;
}