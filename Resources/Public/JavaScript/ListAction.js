let popups = document.querySelectorAll('.popup-action');
if (popups){
    popups.forEach(function(element){
        element.querySelector('button').addEventListener('click', (event) => {
            let content = element.querySelector('.popup-content');
            if (content.classList.contains('hidden')) {
                content.classList.remove('hidden')
            } else {
                content.classList.add('hidden');
            }
        })
    })
}