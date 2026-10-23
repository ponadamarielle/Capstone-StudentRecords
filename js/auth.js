document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-toggle-password]').forEach(function (button) {
        const input = document.getElementById(button.getAttribute('data-toggle-password'));
        const icon = button.querySelector('.bi');
        if (!input) return;
        button.addEventListener('click', function () {
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            if (icon) icon.className = show ? 'bi bi-eye' : 'bi bi-eye-slash';
            button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        });
    });

    const signinForm = document.getElementById('signinForm');
    const emailInput = document.getElementById('signinEmail');
    const rememberMe = document.getElementById('rememberMe');
    const REMEMBER_KEY = 'pupErecordsRememberEmail';
    if (signinForm && emailInput && rememberMe) {
        let saved = null;
        try {
            saved = window.localStorage.getItem(REMEMBER_KEY);
        } catch (err) {
            saved = null;
        }
        if (saved) {
            rememberMe.checked = true;
            if (!emailInput.value) emailInput.value = saved;
        }
        signinForm.addEventListener('submit', function () {
            try {
                if (rememberMe.checked && emailInput.value.trim()) {
                    window.localStorage.setItem(REMEMBER_KEY, emailInput.value.trim());
                } else {
                    window.localStorage.removeItem(REMEMBER_KEY);
                }
            } catch (err) {
            }
        });
    }

    const forgotBtn = document.getElementById('forgotPasswordBtn');
    const forgotNote = document.getElementById('forgotPasswordNote');
    if (forgotBtn && forgotNote) {
        forgotBtn.addEventListener('click', function () {
            forgotNote.hidden = !forgotNote.hidden;
        });
    }

    const newPassword = document.getElementById('new_password');
    const strength = document.getElementById('passwordStrength');
    const strengthLabel = document.getElementById('passwordStrengthLabel');
    const rules = document.getElementById('passwordRules');
    if (newPassword && strength && rules) {
        const checks = {
            length: (v) => v.length >= 8,
            case: (v) => /[A-Z]/.test(v) && /[a-z]/.test(v),
            number: (v) => /[0-9]/.test(v),
            symbol: (v) => /[\W_]/.test(v),
        };
        const labels = ['', 'Weak', 'Fair', 'Good', 'Strong'];
        const update = function () {
            const value = newPassword.value;
            let met = 0;
            rules.querySelectorAll('[data-rule]').forEach(function (item) {
                const ok = checks[item.getAttribute('data-rule')](value);
                if (ok) met++;
                item.classList.toggle('is-met', ok);
                const icon = item.querySelector('.bi');
                if (icon) icon.className = ok ? 'bi bi-check-circle-fill' : 'bi bi-info-circle';
            });
            const level = value ? Math.max(1, met) : 0;
            strength.setAttribute('data-level', String(level));
            if (strengthLabel) strengthLabel.textContent = labels[level];
        };
        newPassword.addEventListener('input', update);
        update();
    }
});