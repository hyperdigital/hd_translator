/**
 * Detail screen: saving, the import upload field and the client side filter.
 *
 * The dropdown handling that used to live here is gone, the screens use the
 * backend's own dropdown component now.
 */
const form = document.getElementById('translation-form');

const saveAction = () => {
    if (!form) {
        return;
    }

    const formData = new FormData(form);
    const data = {};
    formData.forEach((value, key) => (data[key] = value));

    fetch(form.getAttribute('action'), {
        method: 'POST',
        body: JSON.stringify(data),
    })
        .then((response) => (response.ok ? response.json() : Promise.reject(response)))
        .then((payload) => {
            if (payload.success) {
                top.TYPO3.Notification.success(form.getAttribute('data-message-success'));
            } else {
                top.TYPO3.Notification.error(form.getAttribute('data-message-error'));
            }
        })
        .catch((error) => {
            console.warn(error);
            top.TYPO3.Notification.error(form.getAttribute('data-message-error'));
        });
};

const saveButton = document.querySelector('a[data-action=save]');
if (saveButton) {
    saveButton.addEventListener('click', (event) => {
        event.preventDefault();
        saveAction();
    });
}

if (form) {
    form.addEventListener('submit', (event) => {
        event.preventDefault();
        saveAction();
    });
}

// Upload button proxies the hidden file input and shows the chosen file name
document.querySelectorAll('.js-hd_translator-fileupload-popup').forEach((button) => {
    const uploadForm = button.closest('form');
    const fileInput = uploadForm?.querySelector('.js-hd_translator-fileupload-target');
    const display = uploadForm?.querySelector('.file-name-display');

    if (!fileInput) {
        return;
    }

    button.addEventListener('click', () => fileInput.click());
    fileInput.addEventListener('change', () => {
        if (display) {
            display.textContent = fileInput.files.length > 0 ? fileInput.files[0].name : '';
        }
    });
});

// Client side filter over the rendered strings
const search = document.getElementById('hd_translator-search');
const items = document.querySelectorAll('.hd-translator-detail-grid-item');
const noResults = document.getElementById('hd-translator-no-results');

if (search && items.length) {
    const filter = () => {
        const terms = search.value.toLowerCase().trim().split(' ').filter((t) => t.length > 1);
        let visible = 0;

        items.forEach((item) => {
            const haystack = item.getAttribute('data-search') || '';
            const matches = terms.length === 0 || terms.every((term) => haystack.includes(term));

            item.classList.toggle('hidden', !matches);
            if (matches) {
                visible++;
            }
        });

        // category headings without a visible string below them only add noise
        document.querySelectorAll('.hd-translator-detail-categorytitle').forEach((title) => {
            title.classList.toggle('hidden', terms.length > 0);
        });

        if (noResults) {
            noResults.classList.toggle('hidden', visible > 0);
        }
    };

    search.addEventListener('input', filter);
    search.addEventListener('search', filter);
}

// Reference languages: the values are rendered hidden, the menu only toggles them
const compareMenu = document.getElementById('hd-translator-compare-menu');
if (compareMenu) {
    const storageKey = 'hd_translator.compareLanguages';

    const apply = (language, visible) => {
        document
            .querySelectorAll('[data-compare-language="' + CSS.escape(language) + '"]')
            .forEach((row) => row.classList.toggle('hidden', !visible));
    };

    const remember = () => {
        const active = [...compareMenu.querySelectorAll('[data-compare-toggle]:checked')]
            .map((box) => box.dataset.compareToggle);
        localStorage.setItem(storageKey, JSON.stringify(active));
    };

    let restored = [];
    try {
        restored = JSON.parse(localStorage.getItem(storageKey) || '[]');
    } catch (e) {
        restored = [];
    }

    compareMenu.querySelectorAll('[data-compare-toggle]').forEach((box) => {
        if (restored.includes(box.dataset.compareToggle)) {
            box.checked = true;
            apply(box.dataset.compareToggle, true);
        }

        box.addEventListener('change', () => {
            apply(box.dataset.compareToggle, box.checked);
            remember();
        });
    });
}

// Multiline: swaps the single line fields for text areas and back, keeping the value
const multilineToggle = document.getElementById('hd-translator-multiline-toggle');
if (multilineToggle && form) {
    const storageKey = 'hd_translator.multiline';

    const swap = (field, tagName) => {
        const replacement = document.createElement(tagName);

        replacement.value = field.value;
        replacement.className = field.className;
        ['id', 'name', 'dir', 'lang', 'placeholder'].forEach((attribute) => {
            const value = field.getAttribute(attribute);
            if (value !== null) {
                replacement.setAttribute(attribute, value);
            }
        });

        if (tagName === 'input') {
            replacement.setAttribute('type', 'text');
        }

        field.replaceWith(replacement);
    };

    const setMultiline = (enabled) => {
        form.querySelectorAll('.hd-translator-field > ' + (enabled ? 'input' : 'textarea'))
            .forEach((field) => swap(field, enabled ? 'textarea' : 'input'));

        multilineToggle.setAttribute('aria-pressed', enabled ? 'true' : 'false');
        multilineToggle.classList.toggle('active', enabled);
        localStorage.setItem(storageKey, enabled ? '1' : '0');
    };

    if (localStorage.getItem(storageKey) === '1') {
        setMultiline(true);
    }

    multilineToggle.addEventListener('click', () => {
        setMultiline(multilineToggle.getAttribute('aria-pressed') !== 'true');
    });
}
