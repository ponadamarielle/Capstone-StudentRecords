document.addEventListener('DOMContentLoaded', function () {
    const logoutModal = document.getElementById('logoutModal');

    if (logoutModal) {
        logoutModal.addEventListener('hide.bs.modal', function () {
            if (document.activeElement) {
                document.activeElement.blur();
            }
        });
    }
});