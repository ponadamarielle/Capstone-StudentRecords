document.addEventListener('DOMContentLoaded', function () {
    function deactivatedText(ms, label) {
        var seconds = Math.max(0, Math.floor((Date.now() - ms) / 1000));
        if (seconds < 60) return label + ' just now';
        var minutes = Math.floor(seconds / 60);
        if (minutes < 60) return label + ' ' + minutes + (minutes === 1 ? ' min ago' : ' mins ago');
        var hours = Math.floor(minutes / 60);
        if (hours < 24) return label + ' ' + hours + (hours === 1 ? ' hr ago' : ' hrs ago');
        var days = Math.floor(hours / 24);
        if (days < 7) return label + ' ' + days + (days === 1 ? ' day ago' : ' days ago');
        return label + ' on ' + new Date(ms).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
    }
    function refreshDeactivatedNotes() {
        document.querySelectorAll('[data-deactivated-at]').forEach(function (el) {
            var ms = Number(el.getAttribute('data-deactivated-at'));
            if (ms) el.textContent = deactivatedText(ms, el.getAttribute('data-note-label') || 'Deactivated');
        });
    }
    refreshDeactivatedNotes();
    setInterval(refreshDeactivatedNotes, 30000);

    // filter tabs
    function goToList(changes) {
        var params = new URLSearchParams(window.location.search);
        Object.keys(changes).forEach(function (key) {
            if (changes[key] && changes[key] !== 'all') {
                params.set(key, changes[key]);
            } else {
                params.delete(key);
            }
        });
        var query = params.toString();
        window.location = 'admin_management.php' + (query ? '?' + query : '');
    }
    document.querySelectorAll('.filter-tab').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (btn.hasAttribute('data-role')) {
                goToList({ role: btn.getAttribute('data-role') });
            } else {
                goToList({ status: btn.getAttribute('data-filter') });
            }
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

var resetModal = document.getElementById('resetPasswordModal');
if (resetModal) {
    resetModal.addEventListener('show.bs.modal', function (e) {
        var btn = e.relatedTarget;
        document.getElementById('resetPasswordInput').value = btn.getAttribute('data-email');
        document.getElementById('resetPasswordEmail').textContent = btn.getAttribute('data-email');
        document.getElementById('resetPasswordName').textContent = btn.getAttribute('data-name');
        var confirmBtn = document.getElementById('resetPasswordConfirm');
        confirmBtn.disabled = false;
        confirmBtn.textContent = 'Reset';
    });
    var resetForm = document.getElementById('resetPasswordForm');
    if (resetForm) {
        resetForm.addEventListener('submit', function () {
            var confirmBtn = document.getElementById('resetPasswordConfirm');
            confirmBtn.disabled = true;
            confirmBtn.textContent = 'Sending...';
        });
    }
}

var NAME_INVALID = /[^\p{L} .'\-]/gu;
var NAME_VALID = /^\p{L}[\p{L} .'\-]*$/u;
var EMAIL_VALID = /^[^@\s]+@[^@\s]+\.[A-Za-z]{2,}$/;
var NAME_MESSAGE = "Name can only contain letters (including ñ), spaces, hyphens (-), periods (.) and apostrophes (').";
var EMAIL_MESSAGE = 'Please enter a valid email address (e.g. name@gmail.com).';

var addForm = document.getElementById('addAdminForm');
var nameInput = document.getElementById('name');
var emailInput = document.getElementById('email');
var roleInput = document.getElementById('role');

function validateRole() {
    if (!roleInput) return true;
    var ok = roleInput.value !== '';
    setFieldError(roleInput, 'roleFeedback', ok ? '' : 'Please choose a role.');
    return ok;
}

if (roleInput) {
    roleInput.addEventListener('change', validateRole);
}

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
        var roleOk = validateRole();
        if (!nameOk || !emailOk || !roleOk) {
            e.preventDefault();
            (!nameOk ? nameInput : (!emailOk ? emailInput : roleInput)).focus();
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