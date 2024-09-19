const sourceTemp = document.getElementById('source-temp');
const source = document.getElementById('source');
const targetTemp = document.getElementById('target-temp');
const target = document.getElementById('target');

sourceTemp.addEventListener('change', function(e) {
    const value = this.value;
    if (value != '') {
        source.value = value;
        this.value = '';
    }
});
targetTemp.addEventListener('change', function(e) {
    const value = this.value;
    if (value != '') {
        target.value = value;
        this.value = '';
    }
});