document.addEventListener('DOMContentLoaded', function () {
    var REFRESH_MS = 5000;
    var loading = false;
    var lastPayload = '';

    function formatNumber(n) {
        return Number(n || 0).toLocaleString('en-US');
    }

    function applyBars() {
        document.querySelectorAll('.dash-bar-seg').forEach(function (seg) {
            var value = Number(seg.dataset.value || 0);
            seg.style.flexGrow = value;
            seg.hidden = value === 0;
        });
    }

    function render(data) {
        ['total', 'enrollees', 'graduates', 'complete', 'incomplete', 'grad_complete', 'grad_incomplete'].forEach(function (key) {
            document.querySelectorAll('[data-stat="' + key + '"]').forEach(function (el) {
                el.textContent = formatNumber(data[key]);
            });
        });
        document.querySelectorAll('[data-stat="percent"]').forEach(function (el) {
            el.textContent = data.percent;
        });
        document.querySelectorAll('[data-stat="grad_percent"]').forEach(function (el) {
            el.textContent = data.grad_percent;
        });

        var enrolleeTotal = data.complete + data.incomplete;
        var completeSeg = document.getElementById('dashBarComplete');
        var incompleteSeg = document.getElementById('dashBarIncomplete');
        var emptyNote = document.getElementById('dashBarEmpty');
        var bar = document.getElementById('dashReqBar');
        if (completeSeg) {
            completeSeg.dataset.value = data.complete;
            completeSeg.title = 'Complete: ' + data.complete;
        }
        if (incompleteSeg) {
            incompleteSeg.dataset.value = data.incomplete;
            incompleteSeg.title = 'Incomplete: ' + data.incomplete;
        }
        if (emptyNote) emptyNote.hidden = enrolleeTotal > 0;
        if (bar) bar.setAttribute('aria-label', data.complete + ' complete, ' + data.incomplete + ' incomplete');

        var gradTotal = (data.grad_complete || 0) + (data.grad_incomplete || 0);
        var gradCompleteSeg = document.getElementById('dashGradBarComplete');
        var gradIncompleteSeg = document.getElementById('dashGradBarIncomplete');
        var gradEmptyNote = document.getElementById('dashGradBarEmpty');
        var gradBar = document.getElementById('dashGradBar');
        if (gradCompleteSeg) {
            gradCompleteSeg.dataset.value = data.grad_complete || 0;
            gradCompleteSeg.title = 'Complete: ' + (data.grad_complete || 0);
        }
        if (gradIncompleteSeg) {
            gradIncompleteSeg.dataset.value = data.grad_incomplete || 0;
            gradIncompleteSeg.title = 'Incomplete: ' + (data.grad_incomplete || 0);
        }
        if (gradEmptyNote) gradEmptyNote.hidden = gradTotal > 0;
        if (gradBar) gradBar.setAttribute('aria-label', (data.grad_complete || 0) + ' complete, ' + (data.grad_incomplete || 0) + ' incomplete');

        var updated = document.getElementById('dashUpdatedAt');
        if (updated && data.updated_at) updated.textContent = data.updated_at;

        applyBars();
    }

    function refresh() {
        if (document.hidden || loading) return;
        loading = true;
        fetch('dashboard.php?action=stats', { cache: 'no-store' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (!data || !data.success) return;
                var payload = JSON.stringify(Object.assign({}, data, { updated_at: '' }));
                if (payload !== lastPayload) {
                    lastPayload = payload;
                    render(data);
                } else {
                    var updated = document.getElementById('dashUpdatedAt');
                    if (updated && data.updated_at) updated.textContent = data.updated_at;
                }
            })
            .catch(function () {})
            .then(function () { loading = false; });
    }

    applyBars();
    setInterval(refresh, REFRESH_MS);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) refresh();
    });
    window.addEventListener('focus', refresh);
});