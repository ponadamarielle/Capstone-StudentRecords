document.addEventListener('DOMContentLoaded', function () {
    var addModal = document.getElementById('addRecordsModal');
    if (addModal && window.bootstrap) {
        bootstrap.Modal.getOrCreateInstance(addModal).show();
    }
});
