const form = document.getElementById('translator-import');
const fileuploadButton = document.getElementById('translator-fileupload-button');
const fileUpload = document.getElementById('files');
const fileUploadPreview = document.getElementById('translator-fileupload-preview');

fileuploadButton.addEventListener("click", (e) => {
    e.preventDefault();
    fileUpload.click();
});

fileUpload.addEventListener("change", (e) => {
    var output = '';

    Object.keys(fileUpload.files).forEach(key => {
        output += '<div><small>'+fileUpload.files[key].name+'</small></div>'
    });

    fileUploadPreview.innerHTML = output;
    console.log(fileUploadPreview);
});

form.addEventListener("submit", (e) => {
    e.preventDefault();

    if (fileUpload.files.length == 0) {
        top.TYPO3.Modal.confirm(
            'Missing import file',
            'Select the file for translation import',
            2,
            [{
                text: 'OK',
                btnClass: 'btn-danger',
                name: 'ok'
            }]
        ).on('confirm.button.ok',() => {
            top.TYPO3.Modal.currentModal.trigger('modal-dismiss');
        });
    } else {
        form.submit();
    }
});
