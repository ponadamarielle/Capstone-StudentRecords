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