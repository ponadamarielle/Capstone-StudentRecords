document.addEventListener('DOMContentLoaded', function () {

    var newPass     = document.getElementById('new_password');
    var confirmPass = document.getElementById('confirm_password');
    var errNew      = document.getElementById('err_new');
    var errConfirm  = document.getElementById('err_confirm');
    var saveBtn     = document.getElementById('save-btn');

    function isPasswordValid(val) {
        return val.length >= 8 &&
               /[A-Z]/.test(val) &&
               /[a-z]/.test(val) &&
               /[0-9]/.test(val) &&
               /[\W_]/.test(val);
    }

    function checkAll() {
        var newVal     = newPass.value;
        var confirmVal = confirmPass.value;

        if (newVal === '' && confirmVal === '') {
            saveBtn.disabled = false;
            return;
        }

        var passOk  = isPasswordValid(newVal);
        var matchOk = confirmVal !== '' && confirmVal === newVal;
        saveBtn.disabled = !(passOk && matchOk);
    }

    newPass.addEventListener('input', function () {
        var val = this.value;

        if (val === '') {
            errNew.textContent = '';
            this.style.borderColor = '#ddd';
        } else if (val.length < 8) {
            errNew.textContent = 'At least 8 characters required.';
            this.style.borderColor = '#dc3545';
        } else if (!/[A-Z]/.test(val)) {
            errNew.textContent = 'Add at least one uppercase letter.';
            this.style.borderColor = '#dc3545';
        } else if (!/[a-z]/.test(val)) {
            errNew.textContent = 'Add at least one lowercase letter.';
            this.style.borderColor = '#dc3545';
        } else if (!/[0-9]/.test(val)) {
            errNew.textContent = 'Add at least one number.';
            this.style.borderColor = '#dc3545';
        } else if (!/[\W_]/.test(val)) {
            errNew.textContent = 'Add at least one symbol.';
            this.style.borderColor = '#dc3545';
        } else {
            errNew.textContent = '';
            this.style.borderColor = '#28a745';
        }

        checkAll();
    });

    confirmPass.addEventListener('input', function () {
        if (this.value === '') {
            errConfirm.textContent = '';
            this.style.borderColor = '#ddd';
        } else if (this.value !== newPass.value) {
            errConfirm.textContent = 'Passwords do not match.';
            this.style.borderColor = '#dc3545';
        } else {
            errConfirm.textContent = '';
            this.style.borderColor = '#28a745';
        }

        checkAll();
    });

});