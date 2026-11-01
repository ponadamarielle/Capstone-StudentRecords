document.addEventListener('DOMContentLoaded', function () {
    var CODE_VALID = /^[A-Z0-9][A-Z0-9\-]{1,29}$/;
    var NAME_VALID = /^[\p{L}0-9][\p{L}0-9 .,'&()\-]{4,254}$/u;
    var ALIAS_VALID = /^[A-Za-z0-9][A-Za-z0-9 .\-]{0,39}$/;

    var formModal = document.getElementById('courseFormModal');
    var form = document.getElementById('courseForm');
    var actionInput = document.getElementById('courseFormAction');
    var idInput = document.getElementById('courseFormId');
    var codeInput = document.getElementById('courseCode');
    var nameInput = document.getElementById('courseName');
    var aliasInput = document.getElementById('courseAliases');
    var title = document.getElementById('courseFormTitle');
    var subtitle = document.getElementById('courseFormSubtitle');
    var submitLabel = document.querySelector('#courseFormSubmit span');

    function setError(input, message) {
        var feedback = document.getElementById(input.id + 'Feedback');
        input.classList.toggle('is-invalid', !!message);
        if (feedback) feedback.textContent = message || '';
    }

    function validateCode() {
        var ok = CODE_VALID.test(codeInput.value.trim());
        setError(codeInput, ok ? '' : 'Use 2 to 30 capital letters, numbers or hyphens (e.g. BSA or BSBA-MM).');
        return ok;
    }

    function validateName() {
        var ok = NAME_VALID.test(nameInput.value.trim());
        setError(nameInput, ok ? '' : 'Enter the full program name (e.g. Bachelor of Science in Accountancy).');
        return ok;
    }

    function validateAliases() {
        var parts = aliasInput.value.split(',').map(function (a) { return a.trim(); }).filter(Boolean);
        var ok = parts.length <= 10 && parts.every(function (a) { return ALIAS_VALID.test(a); });
        setError(aliasInput, ok ? '' : (parts.length > 10 ? 'Up to 10 other names only.' : 'Other names can only use letters, numbers, spaces, periods and hyphens, separated by commas.'));
        return ok;
    }

    if (formModal) {
        formModal.addEventListener('show.bs.modal', function (e) {
            var btn = e.relatedTarget;
            if (!btn) return;
            var edit = btn.getAttribute('data-mode') === 'edit';
            actionInput.value = edit ? 'edit' : 'add';
            idInput.value = edit ? btn.getAttribute('data-id') : '';
            codeInput.value = btn.getAttribute('data-code') || '';
            nameInput.value = btn.getAttribute('data-name') || '';
            aliasInput.value = btn.getAttribute('data-aliases') || '';
            title.textContent = edit ? 'Edit course' : 'Add course';
            subtitle.textContent = edit
                ? 'Changes also update the students already in this course.'
                : 'The course appears in the course filters and is recognized in uploaded documents.';
            submitLabel.textContent = edit ? 'Save changes' : 'Add course';
            [codeInput, nameInput, aliasInput].forEach(function (input) { setError(input, ''); });
        });
        formModal.addEventListener('shown.bs.modal', function () {
            (codeInput.value ? nameInput : codeInput).focus();
        });
    }

    if (codeInput) {
        codeInput.addEventListener('input', function () {
            var pos = codeInput.selectionStart;
            var cleaned = codeInput.value.toUpperCase().replace(/[^A-Z0-9\-]/g, '');
            if (cleaned !== codeInput.value) {
                var removed = codeInput.value.length - cleaned.length;
                codeInput.value = cleaned;
                codeInput.setSelectionRange(Math.max(0, pos - removed), Math.max(0, pos - removed));
            }
            if (codeInput.classList.contains('is-invalid')) validateCode();
        });
        nameInput.addEventListener('input', function () {
            if (nameInput.classList.contains('is-invalid')) validateName();
        });
        aliasInput.addEventListener('input', function () {
            if (aliasInput.classList.contains('is-invalid')) validateAliases();
        });
    }

    if (form) {
        form.addEventListener('submit', function (e) {
            var codeOk = validateCode();
            var nameOk = validateName();
            var aliasOk = validateAliases();
            if (!codeOk || !nameOk || !aliasOk) {
                e.preventDefault();
                (!codeOk ? codeInput : (!nameOk ? nameInput : aliasInput)).focus();
            }
        });
    }

    var assignModal = document.getElementById('assignModal');
    if (assignModal) {
        assignModal.addEventListener('show.bs.modal', function (e) {
            var btn = e.relatedTarget;
            if (!btn) return;
            var raw = btn.getAttribute('data-raw') || '';
            var total = parseInt(btn.getAttribute('data-total') || '0', 10);
            document.getElementById('assignRaw').value = raw;
            document.getElementById('assignRawText').textContent = raw;
            document.getElementById('assignRememberText').textContent = raw;
            document.getElementById('assignTotal').textContent = total + ' student record' + (total === 1 ? '' : 's');
            document.getElementById('assignCourse').value = '';
            var remember = document.getElementById('assignRemember');
            var short = /^[A-Za-z0-9][A-Za-z0-9 .\-]{0,39}$/.test(raw.trim());
            remember.checked = short;
            remember.disabled = !short;
            remember.closest('.cm-check').classList.toggle('cm-check-disabled', !short);
        });
    }

    var toggleModal = document.getElementById('toggleModal');
    if (toggleModal) {
        toggleModal.addEventListener('show.bs.modal', function (e) {
            var btn = e.relatedTarget;
            if (!btn) return;
            var active = btn.getAttribute('data-active') === '1';
            var total = parseInt(btn.getAttribute('data-total') || '0', 10);
            document.getElementById('toggleId').value = btn.getAttribute('data-id');
            document.getElementById('toggleTitle').textContent = (active ? 'Mark as not offered: ' : 'Mark as offered: ') + btn.getAttribute('data-display') + '?';
            document.getElementById('toggleText').textContent = active
                ? (total
                    ? total + ' student record' + (total === 1 ? '' : 's') + ' keep this course. It is hidden from the course filters once no student uses it.'
                    : 'It is hidden from the course filters. You can mark it as offered again anytime.')
                : 'It appears again in the course filters.';
            var confirm = document.getElementById('toggleConfirm');
            confirm.textContent = active ? 'Mark as not offered' : 'Mark as offered';
        });
    }

    var flags = document.getElementById('cmFlags');
    if (flags && flags.getAttribute('data-open-form') === 'true' && formModal) {
        bootstrap.Modal.getOrCreateInstance(formModal).show();
    }
});