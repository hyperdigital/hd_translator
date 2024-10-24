let save = document.querySelector('a[data-action=save]')
if (save){
    save.addEventListener('click', (event) => {
        saveAction()

        event.stopPropagation();
    })
}

document.getElementById('translation-form').addEventListener('submit', (event) => {
    saveAction()
    event.stopPropagation();
})

const saveAction = () => {
    const fromElement = document.getElementById('translation-form');
    const formData = new FormData(fromElement);
    let data = {};
    formData.forEach((value, key) => (data[key] = value));
    data = JSON.stringify(data);
    fetch(fromElement.getAttribute('action'), {
        method: 'POST',
        body: data
        ,
    }).then((response) => {
        if (response.ok) {
            return response.json();
        }
        return Promise.reject(response);
    }).then((data) => {
        if (data.success) {
            top.TYPO3.Notification.success(
                fromElement.getAttribute('data-message-success')
            );
        } else {
            top.TYPO3.Notification.error(
                fromElement.getAttribute('data-message-error')
            );
        }
    }).catch((error) => {
        console.warn(error);
        top.TYPO3.Notification.error(
            fromElement.getAttribute('data-message-error')
        );
    });
}

document.querySelectorAll('.popup-action button').forEach((button) => {
    button.addEventListener('click', function(event) {
        const content = button.parentNode.querySelector('.popup-content');
        content.classList.toggle('hidden');
        document.addEventListener('click', function closeOnClickOutside(e) {
            if (!content.contains(e.target) && !button.contains(e.target)) {
                content.classList.add('hidden');
                document.removeEventListener('click', closeOnClickOutside);
            }
        });
    });
});

document.querySelectorAll('.js-hd_translator-fileupload-popup').forEach((button) => {
    button.addEventListener('click', () => {
        // Find the file input element with the class "js-hd_translator-fileupload-target"
        const fileInput = button.closest('form').querySelector('.js-hd_translator-fileupload-target');

        // Trigger the file input click when the button is clicked
        fileInput.click();

        // Add a change event listener to the file input to display the filename
        fileInput.addEventListener('change', () => {
            const fileName = fileInput.files.length > 0 ? fileInput.files[0].name : 'No file selected';
            console.log('Selected file:', fileName);
            // You can display the file name somewhere on the page, for example:
            button.closest('form').querySelector('.file-name-display').textContent = fileName;
        });
    });
});

let search = document.querySelector('#hd_translator-search');
let dataItems = document.querySelectorAll('.hd-translator-detail-grid-item')

const searchAction = (event) => {
    let searchStrings = search.value.toLowerCase().trim().split(' ');
    if (search.value.length > 2 && searchStrings.length > 0) {
        dataItems.forEach((item) => {
            let allFound = true;
            let dataSearchString = item.getAttribute('data-search');

            searchStrings.forEach((subsearch) => {
                let subsearchCleanString = subsearch.trim();

                if (subsearchCleanString.length > 1) {
                    if (!dataSearchString.includes(subsearchCleanString)) {
                        allFound = false;
                    }
                }
            })

            if (!allFound) {
                if (!item.classList.contains('hidden')) {
                    item.classList.add('hidden');
                }
            } else {
                if (item.classList.contains('hidden')) {
                    item.classList.remove('hidden')
                }
            }
        })
    } else {
        dataItems.forEach((item) => {
            if (item.classList.contains('hidden')) {
                item.classList.remove('hidden')
            }
        })
    }
}

if (search && dataItems) {
    search.addEventListener('keyup', searchAction );
    search.addEventListener('change', searchAction );
}