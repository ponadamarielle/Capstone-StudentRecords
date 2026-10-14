document.addEventListener('DOMContentLoaded', function () {
    // filter tabs
    document.querySelectorAll('.filter-tab').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var filter = btn.getAttribute('data-filter');
            window.location = 'user_management.php?status=' + filter;
        });
    });

var confirmModal = document.getElementById('confirmToggleModal');
if (confirmModal) {
    confirmModal.addEventListener('show.bs.modal', function (e) {
        var btn = e.relatedTarget;
        document.getElementById('modalEmail').value = btn.getAttribute('data-email');
        document.getElementById('modalStatus').value = btn.getAttribute('data-status');
        document.getElementById('modalConfirmBtn').innerText = btn.getAttribute('data-label');
    });
}

var NAME_INVALID = /[^\p{L} .'\-]/gu;
var NAME_VALID = /^\p{L}[\p{L} .'\-]*$/u;
var EMAIL_VALID = /^[^@\s]+@[^@\s]+\.[A-Za-z]{2,}$/;
var NAME_MESSAGE = "Name can only contain letters (including ñ), spaces, hyphens (-), periods (.) and apostrophes (').";
var EMAIL_MESSAGE = 'Please enter a valid email address (e.g. name@gmail.com).';

var addForm = document.getElementById('addAdminForm');
var nameInput = document.getElementById('name');
var emailInput = document.getElementById('email');

function setFieldError(input, feedbackId, message) {
    var feedback = document.getElementById(feedbackId);
    input.classList.toggle('is-invalid', !!message);
    if (feedback) feedback.textContent = message || '';
}

function validateName() {
    var value = nameInput.value.trim();
    var ok = value !== '' && NAME_VALID.test(value);
    setFieldError(nameInput, 'nameFeedback', ok ? '' : (value === '' ? 'Please enter a name.' : NAME_MESSAGE));
    return ok;
}

function validateEmail() {
    var value = emailInput.value.trim();
    var ok = EMAIL_VALID.test(value);
    setFieldError(emailInput, 'emailFeedback', ok ? '' : (value === '' ? 'Please enter an email address.' : EMAIL_MESSAGE));
    return ok;
}

if (nameInput) {
    nameInput.addEventListener('input', function () {
        var cleaned = nameInput.value.replace(NAME_INVALID, '').replace(/^[ .'\-]+/, '');
        if (cleaned !== nameInput.value) {
            var pos = nameInput.selectionStart - (nameInput.value.length - cleaned.length);
            nameInput.value = cleaned;
            nameInput.setSelectionRange(Math.max(0, pos), Math.max(0, pos));
        }
        if (nameInput.classList.contains('is-invalid')) validateName();
    });
    nameInput.addEventListener('blur', function () {
        if (nameInput.value.trim() !== '') validateName();
    });
}

if (emailInput) {
    emailInput.addEventListener('input', function () {
        if (emailInput.classList.contains('is-invalid')) validateEmail();
    });
    emailInput.addEventListener('blur', function () {
        if (emailInput.value.trim() !== '') validateEmail();
    });
}

if (addForm) {
    addForm.addEventListener('submit', function (e) {
        var nameOk = validateName();
        var emailOk = validateEmail();
        if (!nameOk || !emailOk) {
            e.preventDefault();
            (nameOk ? emailInput : nameInput).focus();
        }
    });
}

var flags = document.getElementById('jsFlags');
if (!flags) return;

if (flags.getAttribute('data-show-add-modal') === 'true') {
    var addModalEl = document.getElementById('addStudentModal');
if (addModalEl) {
    var addModal = new bootstrap.Modal(addModalEl);
    addModal.show();
}
}

// deactivating last active admin account
if (flags.getAttribute('data-show-toggle-error') === 'true') {
    var toggleErrorEl = document.getElementById('toggleErrorModal');
if (toggleErrorEl) {
    var toggleErrorModal = new bootstrap.Modal(toggleErrorEl);
    toggleErrorModal.show();
}
}

});