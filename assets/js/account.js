(function () {
    'use strict';
    const form = document.getElementById('account-password-form');
    if (!form) return;
    const next = document.getElementById('new_password');
    const confirm = document.getElementById('confirm_password');
    const lengthRule = document.getElementById('password-length-rule');
    const matchRule = document.getElementById('password-match-rule');
    function update() {
        const matches = next.value !== '' && next.value === confirm.value;
        lengthRule.classList.toggle('is-met', next.value.length >= 8);
        matchRule.classList.toggle('is-met', matches);
        confirm.setCustomValidity(confirm.value !== '' && !matches ? 'The new passwords must match.' : '');
    }
    next.addEventListener('input', update);
    confirm.addEventListener('input', update);
    next.addEventListener('change', update);
    confirm.addEventListener('change', update);
    form.addEventListener('submit', function (event) {
        update();
        if (!form.reportValidity()) {
            event.preventDefault();
            event.stopImmediatePropagation();
        }
    }, true);
    update();
})();
