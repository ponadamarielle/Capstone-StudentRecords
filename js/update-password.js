document.addEventListener('DOMContentLoaded', function () {
    const modalEl = document.getElementById('changePasswordModal');
    const form = document.getElementById('changePasswordForm');
    if (!modalEl || !form) return;

    const saveBtn = document.getElementById('cpSaveBtn');
    const cancelBtn = document.getElementById('cpCancelBtn');
    const fields = document.getElementById('cpFields');
    const success = document.getElementById('cpSuccess');
    const currentInput = form.current_password;
    const newInput = form.new_password;
    const confirmInput = form.confirm_password;
    const RULE_MESSAGE = 'Use at least 8 characters with at least one letter and one number.';
    let busy = false;
    let closeTimer = null;
    let currentChecked = '';
    let currentOk = false;
    let checkSeq = 0;

    function showMessage(field, message, ok) {
        const box = form.querySelector('.cp-error[data-error-for="' + field + '"]');
        const input = form.querySelector('[name="' + field + '"]');
        if (box) {
            box.textContent = message || '';
            box.classList.toggle('is-ok', !!message && !!ok);
        }
        if (input) {
            input.classList.toggle('is-invalid', !!message && !ok);
            input.classList.toggle('is-valid-match', !!message && !!ok);
        }
    }

    function clearAll() {
        ['current_password', 'new_password', 'confirm_password', ''].forEach(function (field) {
            showMessage(field, '');
        });
    }

    function rulesOk(value) {
        return value.length >= 8 && /[A-Za-z]/.test(value) && /\d/.test(value);
    }

    function checkNew(final) {
        const value = newInput.value;
        if (value === '') {
            showMessage('new_password', final ? 'Please enter a new password.' : '');
            return false;
        }
        if (currentInput.value !== '' && value === currentInput.value) {
            showMessage('new_password', 'The new password must be different from your current password.');
            return false;
        }
        if (!rulesOk(value)) {
            showMessage('new_password', final || newInput.dataset.touched === '1' ? RULE_MESSAGE : '');
            return false;
        }
        showMessage('new_password', '');
        return true;
    }

    function checkConfirm(final) {
        const value = confirmInput.value;
        if (value === '') {
            showMessage('confirm_password', final ? 'Please confirm the new password.' : '');
            return false;
        }
        if (value !== newInput.value) {
            showMessage('confirm_password', 'The passwords do not match.');
            return false;
        }
        showMessage('confirm_password', 'The passwords match.', true);
        hideCurrentOkText();
        return true;
    }

    function hideCurrentOkText() {
        if (!currentOk || currentChecked !== currentInput.value) return;
        const box = form.querySelector('.cp-error[data-error-for="current_password"]');
        if (box && box.classList.contains('is-ok')) box.textContent = '';
    }

    async function postForm(extra) {
        const data = new FormData(form);
        Object.keys(extra || {}).forEach(function (key) { data.set(key, extra[key]); });
        const res = await fetch(form.action, { method: 'POST', body: data, credentials: 'same-origin' });
        const raw = await res.text();
        if (res.status === 401) {
            window.location = '../signin.php';
            throw new Error('Your session has ended.');
        }
        try {
            return JSON.parse(raw);
        } catch (err) {
            throw new Error('The server returned an unexpected response (HTTP ' + res.status + ').');
        }
    }

    async function checkCurrent() {
        const value = currentInput.value;
        if (value === '') {
            currentChecked = '';
            currentOk = false;
            showMessage('current_password', '');
            return;
        }
        if (value === currentChecked) return;
        const seq = ++checkSeq;
        try {
            const data = await postForm({ check_only: '1' });
            if (seq !== checkSeq || currentInput.value !== value) return;
            currentChecked = value;
            currentOk = !!data.success;
            showMessage('current_password', data.success ? 'Current password is correct.' : (data.message || 'The current password is incorrect.'), data.success);
            if (data.success && confirmInput.value !== '' && confirmInput.value === newInput.value && newInput.value !== currentInput.value) {
                hideCurrentOkText();
            }
        } catch (err) {
            if (seq === checkSeq) showMessage('current_password', err.message);
        }
        if (currentOk && newInput.value !== '') {
            checkNew(false);
        } else if (!currentOk) {
            showMessage('new_password', '');
        }
    }

    function setBusy(on) {
        busy = on;
        saveBtn.disabled = on;
        cancelBtn.disabled = on;
        saveBtn.textContent = on ? 'Saving…' : 'Save password';
    }

    function reset() {
        clearTimeout(closeTimer);
        form.reset();
        clearAll();
        setBusy(false);
        currentChecked = '';
        currentOk = false;
        newInput.dataset.touched = '';
        fields.classList.remove('d-none');
        success.classList.add('d-none');
        saveBtn.classList.remove('d-none');
        cancelBtn.textContent = 'Cancel';
        form.querySelectorAll('.cp-toggle').forEach(function (btn) {
            const input = document.getElementById(btn.dataset.target);
            if (input) input.type = 'password';
            btn.querySelector('i').className = 'bi bi-eye';
            btn.setAttribute('aria-label', 'Show password');
        });
    }

    form.querySelectorAll('.cp-toggle').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const input = document.getElementById(btn.dataset.target);
            if (!input) return;
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
    });

    currentInput.addEventListener('input', function () {
        currentOk = false;
        currentChecked = '';
        showMessage('current_password', '');
        if (newInput.value !== '') checkNew(false);
    });
    currentInput.addEventListener('blur', checkCurrent);

    newInput.addEventListener('input', function () {
        checkNew(false);
        if (confirmInput.value !== '') checkConfirm(false);
    });
    newInput.addEventListener('blur', function () {
        if (newInput.value !== '') newInput.dataset.touched = '1';
        checkNew(false);
    });

    confirmInput.addEventListener('input', function () {
        checkConfirm(false);
    });

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        if (busy) return;
        showMessage('', '');
        if (currentInput.value === '') {
            showMessage('current_password', 'Please enter your current password.');
            currentInput.focus();
            return;
        }
        if (currentChecked !== currentInput.value) {
            setBusy(true);
            await checkCurrent();
            setBusy(false);
        }
        if (!currentOk) {
            showMessage('new_password', '');
            currentInput.focus();
            return;
        }
        if (!checkNew(true)) {
            newInput.focus();
            return;
        }
        if (!checkConfirm(true)) {
            confirmInput.focus();
            return;
        }
        setBusy(true);
        try {
            const data = await postForm({});
            setBusy(false);
            if (!data.success) {
                const field = data.field || '';
                if (field === 'current_password') {
                    currentChecked = currentInput.value;
                    currentOk = false;
                }
                showMessage(field, data.message || 'The password could not be changed.');
                const input = form.querySelector('[name="' + field + '"]');
                if (input) input.focus();
                return;
            }
            form.reset();
            fields.classList.add('d-none');
            success.classList.remove('d-none');
            saveBtn.classList.add('d-none');
            cancelBtn.textContent = 'Close';
            closeTimer = setTimeout(function () {
                if (window.bootstrap) bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            }, 2500);
        } catch (err) {
            setBusy(false);
            showMessage('', err.message || 'The password could not be changed. Check the connection and try again.');
        }
    });

    modalEl.addEventListener('hide.bs.modal', function (e) {
        if (busy) e.preventDefault();
    });
    modalEl.addEventListener('hidden.bs.modal', reset);
    modalEl.addEventListener('shown.bs.modal', function () {
        currentInput.focus();
    });
});