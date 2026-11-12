document.addEventListener('DOMContentLoaded', function () {
    const qr = document.querySelector('.tf-qr[data-otpauth]');
    if (qr && window.PupQr) {
        try {
            qr.innerHTML = window.PupQr.svg(qr.dataset.otpauth, 5);
        } catch (err) {
            qr.textContent = 'The QR code could not be shown. Use the setup key instead.';
        }
    }

    const form = document.getElementById('tfCodeForm');
    const code = document.getElementById('tfCode');
    if (form && code) {
        let submitted = false;
        code.addEventListener('input', function () {
            const digits = code.value.replace(/\D/g, '').slice(0, 6);
            if (code.value !== digits) code.value = digits;
            if (digits.length === 6 && !submitted) {
                submitted = true;
                form.requestSubmit ? form.requestSubmit() : form.submit();
            }
        });
        form.addEventListener('submit', function () {
            submitted = true;
            const button = form.querySelector('.auth-submit');
            if (button) {
                button.disabled = true;
                button.textContent = 'Verifying...';
            }
        });
    }

    document.querySelectorAll('[data-wait]').forEach(function (button) {
        let left = parseInt(button.dataset.wait, 10) || 0;
        const label = button.textContent;
        const tick = function () {
            if (left > 0) {
                button.disabled = true;
                button.textContent = label + ' (' + left + 's)';
                left--;
                setTimeout(tick, 1000);
            } else {
                button.disabled = false;
                button.textContent = label;
            }
        };
        tick();
    });

    document.querySelectorAll('[data-copy]').forEach(function (button) {
        button.addEventListener('click', function () {
            const target = document.getElementById(button.dataset.copy);
            const text = target ? target.textContent.replace(/\s+/g, '') : '';
            const label = button.querySelector('span');
            const done = function () {
                if (label) label.textContent = 'Copied';
                setTimeout(function () { if (label) label.textContent = 'Copy key'; }, 2000);
            };
            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(text).then(done).catch(function () {});
            } else {
                const area = document.createElement('textarea');
                area.value = text;
                document.body.appendChild(area);
                area.select();
                try { document.execCommand('copy'); done(); } catch (err) {}
                area.remove();
            }
        });
    });
});
