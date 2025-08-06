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

async function hdtranslator_translateWholePage(targetLang) {
  // 1) Collect nodes + their whitespace
  const textNodes = hdtranslator_collectTextNodes(document.body);
  const nodesInfo = textNodes.map(node => {
    const raw = node.nodeValue;
    const leading  = raw.match(/^\s*/)[0];   // whitespace before
    const trailing = raw.match(/\s*$/)[0];   // whitespace after
    const coreText = raw.trim();             // text to translate
    return { node, leading, coreText, trailing };
  });

  // 2) Batch up just the core texts
  const BATCH_SIZE = 50;
  for (let i = 0; i < nodesInfo.length; i += BATCH_SIZE) {
    const batchInfo = nodesInfo.slice(i, i + BATCH_SIZE);
    const batchTexts = batchInfo.map(info => info.coreText);
    const translatedBatch = await hdtranslator_translateTextBatch(batchTexts, targetLang);
    if (!translatedBatch) continue;

    // 3) Re-assign nodeValue with whitespace restored
    translatedBatch.forEach((translated, idx) => {
      if (translated) {
        const {node, leading, trailing} = batchInfo[idx];
        node.nodeValue = leading + translated + trailing;
      }
    });
  }

  console.log("Page translation complete with whitespace preserved.");
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
