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
