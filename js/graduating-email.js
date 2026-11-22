document.addEventListener('DOMContentLoaded', function () {
    const modalEl = document.getElementById('emailSendModal');
    const modal = modalEl && window.bootstrap ? bootstrap.Modal.getOrCreateInstance(modalEl) : null;
    const icon = modalEl ? modalEl.querySelector('[data-email-icon]') : null;
    const title = modalEl ? modalEl.querySelector('[data-email-title]') : null;
    const text = modalEl ? modalEl.querySelector('[data-email-text]') : null;
    const cancelBtn = modalEl ? modalEl.querySelector('[data-email-cancel]') : null;
    const confirmBtn = modalEl ? modalEl.querySelector('[data-email-confirm]') : null;
    let busy = false;
    let onConfirm = null;
    let reloadOnClose = false;

    function showModal(opts) {
        icon.className = 'email-modal-icon' + (opts.tone ? ' is-' + opts.tone : '');
        icon.innerHTML = '<i class="bi ' + (opts.icon || 'bi-envelope') + '"></i>';
        title.textContent = opts.title;
        text.textContent = opts.text;
        cancelBtn.hidden = !opts.cancel;
        confirmBtn.innerHTML = opts.confirmHtml;
        confirmBtn.disabled = false;
        cancelBtn.disabled = false;
        onConfirm = opts.onConfirm;
        modal.show();
    }

    if (modalEl) {
        confirmBtn.addEventListener('click', function () {
            if (!busy && onConfirm) onConfirm();
        });
        modalEl.addEventListener('hide.bs.modal', function (e) {
            if (busy) e.preventDefault();
        });
        modalEl.addEventListener('hidden.bs.modal', function () {
            if (reloadOnClose) window.location.reload();
        });
    }

    async function send(action) {
        const response = await fetch('student_directory.php?action=' + action, { method: 'POST' });
        return response.json();
    }

    function setup(buttonId, action, copy) {
        const button = document.getElementById(buttonId);
        if (!button) return;

        async function runFallback(count) {
            if (!window.confirm(copy.question(count))) return;
            button.disabled = true;
            try {
                const result = await send(action);
                window.alert(result.message || 'The emails could not be sent.');
                if (result.success) {
                    window.location.reload();
                    return;
                }
            } catch (err) {
                window.alert('The emails could not be sent. Please try again.');
            }
            button.disabled = false;
        }

        async function runModal() {
            busy = true;
            confirmBtn.disabled = true;
            cancelBtn.disabled = true;
            confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> Sending…';
            let result;
            try {
                result = await send(action);
            } catch (err) {
                result = { success: false, message: 'The emails could not be sent. Please try again.' };
            }
            busy = false;
            const partial = result.success && result.failed > 0;
            reloadOnClose = !!result.success;
            showModal({
                tone: result.success && !partial ? 'success' : 'error',
                icon: result.success && !partial ? 'bi-check-lg' : 'bi-exclamation-lg',
                title: result.success ? (partial ? 'Some emails were not sent' : 'Emails sent') : 'Emails not sent',
                text: result.message || 'The emails could not be sent.',
                cancel: false,
                confirmHtml: 'OK',
                onConfirm: function () {
                    if (result.success) {
                        window.location.reload();
                    } else {
                        modal.hide();
                    }
                },
            });
        }

        button.addEventListener('click', function () {
            const count = parseInt(button.dataset.count, 10) || 0;
            if (!modal) {
                runFallback(count);
                return;
            }
            showModal({
                icon: 'bi-envelope',
                title: copy.title,
                text: copy.question(count),
                cancel: true,
                confirmHtml: '<i class="bi bi-send"></i> ' + copy.confirm,
                onConfirm: runModal,
            });
        });
    }

    setup('emailGraduatingBtn', 'notify_graduating', {
        title: 'Email graduating students',
        confirm: 'Email all',
        question: function (count) {
            return 'Send the graduation requirements email to ' + count + ' graduating student' + (count === 1 ? '' : 's') + '?';
        },
    });

    setup('emailUnsentBtn', 'notify_unsent', {
        title: 'Send unsent emails',
        confirm: 'Send all',
        question: function (count) {
            return 'Send the ' + count + ' requirements email' + (count === 1 ? '' : 's') + ' that could not be sent before?';
        },
    });
});