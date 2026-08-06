/**
 * Copies a language code picked from the quick select into the text field of the
 * same input group, then resets the select.
 *
 * Replaces the three near identical scripts the export screens used to ship.
 */
document.querySelectorAll('select.hd-translator-quickselect').forEach((select) => {
    select.addEventListener('change', function () {
        if (this.value === '') {
            return;
        }

        const target = this.closest('.input-group')?.querySelector('input[type="text"]');
        if (target) {
            target.value = this.value;
            target.dispatchEvent(new Event('change', { bubbles: true }));
        }

        this.value = '';
    });
});
