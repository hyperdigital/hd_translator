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


[].slice.call(document.querySelectorAll('.popup-action button')).forEach((button) => {
    button.addEventListener('click', (event) => {
        let content = button.parentNode.querySelector('.popup-content');
        if (content.classList.contains('hidden')) {
            content.classList.remove('hidden')
        } else {
            content.classList.add('hidden');
        }
    })
})

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