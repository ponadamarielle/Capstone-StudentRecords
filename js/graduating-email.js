document.addEventListener('DOMContentLoaded', function () {
    var button = document.getElementById('emailGraduatingBtn');
    if (!button) return;

    button.addEventListener('click', async function () {
        var count = parseInt(button.dataset.count, 10) || 0;
        if (!window.confirm('Send the requirements email to ' + count + ' graduating student' + (count === 1 ? '' : 's') + '?')) return;
        var label = button.querySelector('span');
        button.disabled = true;
        if (label) label.textContent = 'SENDING...';
        try {
            var response = await fetch('student_directory.php?action=notify_graduating', { method: 'POST' });
            var result = await response.json();
            window.alert(result.message || 'The emails could not be sent.');
            if (result.success) {
                window.location.reload();
                return;
            }
        } catch (err) {
            window.alert('The emails could not be sent. Please try again.');
        }
        button.disabled = false;
        if (label) label.textContent = 'EMAIL ALL';
    });
});
