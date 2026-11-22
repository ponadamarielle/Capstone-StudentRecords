document.addEventListener('DOMContentLoaded', function () {
    const modalEl = document.getElementById('changeEmailModal');
    const form = document.getElementById('changeEmailForm');
    if (!modalEl || !form) return;

    const saveBtn = document.getElementById('ceSaveBtn');
    const cancelBtn = document.getElementById('ceCancelBtn');
    const resendBtn = document.getElementById('ceResendBtn');
    const backBtn = document.getElementById('ceBackBtn');
    const stepDetails = document.getElementById('ceStepDetails');
    const stepCode = document.getElementById('ceStepCode');
    const success = document.getElementById('ceSuccess');
    const successText = document.getElementById('ceSuccessText');
    const sentText = document.getElementById('ceSentText');
    const subtitle = document.getElementById('ceSubtitle');
    const currentBox = form.querySelector('.ce-current');
    const passwordInput = form.current_password;
    const emailInput = form.new_email;
    const codeInput = form.code;
    const SUBTITLE = subtitle.textContent;
    const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    let step = 'details';
    let busy = false;
    let closeTimer = null;
    let waitTimer = null;
    let waitLeft = 0;

    function showMessage(field, message, ok) {
        const box = form.querySelector('.cp-error[data-error-for="' + field + '"]');
        const input = field ? form.querySelector('[name="' + field + '"]') : null;
        if (box) {
            box.textContent = message || '';
            box.classList.toggle('is-ok', !!message && !!ok);
        }
        if (input) {
            input.classList.toggle('is-invalid', !!message && !ok);
        }
    }

    function clearAll() {
        ['current_password', 'new_email', 'code', ''].forEach(function (field) {
            showMessage(field, '');
        });
    }

    function saveLabel() {
        return step === 'code' ? 'Verify and save' : 'Send code';
    }

    function setBusy(on, label) {
        busy = on;
        saveBtn.disabled = on;
        cancelBtn.disabled = on;
        resendBtn.disabled = on || waitLeft > 0;
        backBtn.disabled = on;
        saveBtn.textContent = on ? label : saveLabel();
    }

    function startWait(seconds) {
        clearInterval(waitTimer);
        waitLeft = Math.max(0, parseInt(seconds, 10) || 0);
        function tick() {
            resendBtn.disabled = busy || waitLeft > 0;
            resendBtn.textContent = waitLeft > 0 ? 'Resend code (' + waitLeft + 's)' : 'Resend code';
            if (waitLeft <= 0) {
                clearInterval(waitTimer);
                return;
            }
            waitLeft--;
        }
        tick();
        waitTimer = setInterval(tick, 1000);
    }

    function showStep(name, email) {
        step = name;
        stepDetails.classList.toggle('d-none', name !== 'details');
        stepCode.classList.toggle('d-none', name !== 'code');
        saveBtn.textContent = saveLabel();
        if (name === 'code') {
            sentText.textContent = '';
            sentText.append('Enter the 6-digit code we sent to ');
            const strong = document.createElement('strong');
            strong.textContent = email;
            sentText.append(strong, '. The code expires in 10 minutes.');
            subtitle.textContent = 'Check the inbox of the new email address.';
            codeInput.value = '';
            codeInput.focus();
        } else {
            subtitle.textContent = SUBTITLE;
            clearInterval(waitTimer);
            waitLeft = 0;
        }
    }

    async function post(action, extra) {
        const data = new FormData();
        data.set('csrf_token', form.csrf_token.value);
        data.set('action', action);
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

    function handleFailure(data) {
        if (data.restart) {
            showStep('details');
            showMessage('', data.message || 'Please start again.');
            return;
        }
        if (data.wait) startWait(data.wait);
        const field = data.field || '';
        showMessage(field, data.message || 'Something went wrong. Please try again.');
        const input = field ? form.querySelector('[name="' + field + '"]') : null;
        if (input) input.focus();
    }

    async function sendCode() {
        clearAll();
        const email = emailInput.value.trim();
        if (passwordInput.value === '') {
            showMessage('current_password', 'Please enter your current password.');
            passwordInput.focus();
            return;
        }
        if (email === '') {
            showMessage('new_email', 'Please enter the new email address.');
            emailInput.focus();
            return;
        }
        if (!EMAIL_PATTERN.test(email)) {
            showMessage('new_email', 'Please enter a valid email address.');
            emailInput.focus();
            return;
        }
        if (currentBox && email.toLowerCase() === currentBox.textContent.trim().toLowerCase()) {
            showMessage('new_email', 'The new email address must be different from your current email address.');
            emailInput.focus();
            return;
        }
        setBusy(true, 'Sending…');
        try {
            const data = await post('send_code', { current_password: passwordInput.value, new_email: email });
            setBusy(false);
            if (!data.success) {
                handleFailure(data);
                return;
            }
            passwordInput.value = '';
            showStep('code', data.email || email);
            startWait(data.wait || 60);
        } catch (err) {
            setBusy(false);
            showMessage('', err.message || 'The code could not be sent. Check the connection and try again.');
        }
    }

    async function verifyCode() {
        clearAll();
        const code = codeInput.value.replace(/\D/g, '');
        if (code.length !== 6) {
            showMessage('code', 'Enter the 6-digit code.');
            codeInput.focus();
            return;
        }
        setBusy(true, 'Saving…');
        try {
            const data = await post('verify_code', { code: code });
            setBusy(false);
            if (!data.success) {
                handleFailure(data);
                return;
            }
            if (currentBox && data.email) currentBox.textContent = data.email;
            successText.textContent = data.message || 'Your email address was changed.';
            stepCode.classList.add('d-none');
            success.classList.remove('d-none');
            saveBtn.classList.add('d-none');
            cancelBtn.textContent = 'Close';
            subtitle.textContent = SUBTITLE;
            closeTimer = setTimeout(function () {
                if (window.bootstrap) bootstrap.Modal.getOrCreateInstance(modalEl).hide();
            }, 2500);
        } catch (err) {
            setBusy(false);
            showMessage('', err.message || 'The email address could not be changed. Check the connection and try again.');
        }
    }

    function reset() {
        clearTimeout(closeTimer);
        form.reset();
        clearAll();
        setBusy(false);
        showStep('details');
        success.classList.add('d-none');
        saveBtn.classList.remove('d-none');
        cancelBtn.textContent = 'Cancel';
        resendBtn.textContent = 'Resend code';
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

    passwordInput.addEventListener('input', function () { showMessage('current_password', ''); });
    emailInput.addEventListener('input', function () { showMessage('new_email', ''); });
    codeInput.addEventListener('input', function () {
        codeInput.value = codeInput.value.replace(/\D/g, '').slice(0, 6);
        showMessage('code', '');
    });

    resendBtn.addEventListener('click', async function () {
        if (busy || waitLeft > 0) return;
        clearAll();
        setBusy(true, 'Sending…');
        try {
            const data = await post('resend_code', {});
            setBusy(false);
            if (!data.success) {
                handleFailure(data);
                return;
            }
            showMessage('code', 'A new code was sent.', true);
            startWait(data.wait || 60);
            codeInput.focus();
        } catch (err) {
            setBusy(false);
            showMessage('', err.message || 'The code could not be sent.');
        }
    });

    backBtn.addEventListener('click', function () {
        if (busy) return;
        clearAll();
        showStep('details');
        emailInput.focus();
    });

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (busy) return;
        if (step === 'code') {
            verifyCode();
        } else {
            sendCode();
        }
    });

    modalEl.addEventListener('hide.bs.modal', function (e) {
        if (busy) e.preventDefault();
    });
    modalEl.addEventListener('hidden.bs.modal', reset);
    modalEl.addEventListener('shown.bs.modal', function () {
        passwordInput.focus();
    });
});
