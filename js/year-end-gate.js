document.addEventListener('DOMContentLoaded', function () {
    var gate = document.getElementById('yearEndGate');
    if (!gate || !window.bootstrap) return;

    var modal = bootstrap.Modal.getOrCreateInstance(gate, { backdrop: 'static', keyboard: false });
    var button = document.getElementById('yearEndConfirm');
    var error = document.getElementById('yearEndError');
    var done = false;

    gate.addEventListener('hide.bs.modal', function (event) {
        if (!done) event.preventDefault();
    });

    document.querySelectorAll('.modal').forEach(function (other) {
        if (other === gate) return;
        other.addEventListener('show.bs.modal', function (event) {
            if (!done) event.preventDefault();
        });
    });

    modal.show();

    button.addEventListener('click', async function () {
        button.disabled = true;
        button.textContent = 'Updating records...';
        error.hidden = true;
        try {
            var data = new FormData();
            data.append('csrf_token', gate.dataset.csrf);
            var res = await fetch('year_end.php', { method: 'POST', body: data, credentials: 'same-origin' });
            var result = await res.json();
            if (!result.success) throw new Error(result.message);
            done = true;
            modal.hide();
            window.location.reload();
        } catch (e) {
            error.textContent = e && e.message ? e.message : 'The year-end update could not be saved. Try again.';
            error.hidden = false;
            button.disabled = false;
            button.textContent = 'Confirm year-end update';
        }
    });
});
