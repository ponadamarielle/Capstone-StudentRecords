document.addEventListener('DOMContentLoaded', function () {
    var LABEL_VALID = /^[\p{L}\p{N}][\p{L}\p{N}\p{M} .,'&()\/\-]{2,119}$/u;

    var formModal = document.getElementById('requirementFormModal');
    var form = document.getElementById('requirementForm');
    var labelInput = document.getElementById('requirementLabel');

    function setError(input, message) {
        var feedback = document.getElementById(input.id + 'Feedback');
        input.classList.toggle('is-invalid', !!message);
        if (feedback) feedback.textContent = message || '';
    }

    function validateLabel() {
        var value = labelInput.value.replace(/\s+/g, ' ').trim();
        var ok = LABEL_VALID.test(value);
        setError(labelInput, ok ? '' : (value === '' ? 'This field is required.' : 'Enter a valid document name.'));
        return ok;
    }

    if (formModal) {
        formModal.addEventListener('show.bs.modal', function (e) {
            if (!e.relatedTarget) return;
            labelInput.value = '';
            setError(labelInput, '');
        });
        formModal.addEventListener('shown.bs.modal', function () {
            labelInput.focus();
        });
    }

    if (labelInput) {
        labelInput.addEventListener('input', function () {
            var cleaned = labelInput.value.replace(/[^\p{L}\p{N}\p{M} .,'&()\/\-]/gu, '');
            if (cleaned !== labelInput.value) {
                var pos = Math.max(0, labelInput.selectionStart - (labelInput.value.length - cleaned.length));
                labelInput.value = cleaned;
                labelInput.setSelectionRange(pos, pos);
            }
            if (labelInput.classList.contains('is-invalid')) validateLabel();
        });
    }

    if (form) {
        form.addEventListener('submit', function (e) {
            if (!validateLabel()) {
                e.preventDefault();
                labelInput.focus();
            }
            if (e.defaultPrevented) return;
            var submit = form.querySelector('button[type="submit"]');
            if (submit) {
                submit.disabled = true;
                submit.querySelector('span').textContent = 'Saving...';
            }
            labelInput.readOnly = true;
        });
    }

    var removeModal = document.getElementById('removeModal');
    if (removeModal) {
        removeModal.addEventListener('show.bs.modal', function (e) {
            var btn = e.relatedTarget;
            if (!btn) return;
            document.getElementById('removeCode').value = btn.getAttribute('data-code');
            document.getElementById('removeTitle').textContent = 'Remove ' + btn.getAttribute('data-label') + '?';
        });
    }

    var flags = document.getElementById('rqFlags');
    if (flags && flags.getAttribute('data-open-form') === 'true' && formModal) {
        bootstrap.Modal.getOrCreateInstance(formModal).show();
    }
});