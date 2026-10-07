document.addEventListener('DOMContentLoaded', function () {
    var REFRESH_MS = 30000;

    function formatNumber(n) {
        return Number(n || 0).toLocaleString('en-US');
    }

    function applyBars() {
        document.querySelectorAll('.dash-bar-seg').forEach(function (seg) {
            var value = Number(seg.dataset.value || 0);
            seg.style.flexGrow = value;
            seg.hidden = value === 0;
        });
        document.querySelectorAll('.dash-missing-fill').forEach(function (fill) {
            var percent = Math.max(0, Math.min(100, Number(fill.dataset.percent || 0)));
            fill.style.width = percent + '%';
            fill.style.minWidth = percent > 0 ? '4px' : '0';
        });
    }

    function render(data) {
        ['total', 'enrollees', 'graduates', 'complete', 'incomplete'].forEach(function (key) {
            document.querySelectorAll('[data-stat="' + key + '"]').forEach(function (el) {
                el.textContent = formatNumber(data[key]);
            });
        });
        document.querySelectorAll('[data-stat="percent"]').forEach(function (el) {
            el.textContent = data.percent;
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

        var list = document.getElementById('dashMissingList');
        var emptyMissing = document.getElementById('dashMissingEmpty');
        if (list) {
            list.innerHTML = '';
            (data.top_missing || []).forEach(function (item) {
                var li = document.createElement('li');

                var label = document.createElement('span');
                label.className = 'dash-missing-label';
                label.textContent = item.label;

                var track = document.createElement('span');
                track.className = 'dash-missing-track';
                var fill = document.createElement('span');
                fill.className = 'dash-missing-fill';
                fill.dataset.percent = enrolleeTotal > 0 ? Math.round(item.students / enrolleeTotal * 1000) / 10 : 0;
                track.appendChild(fill);

                var value = document.createElement('span');
                value.className = 'dash-missing-value';
                value.textContent = item.students + (item.students === 1 ? ' student' : ' students');

                li.appendChild(label);
                li.appendChild(track);
                li.appendChild(value);
                list.appendChild(li);
            });
        }
        if (emptyMissing) emptyMissing.hidden = (data.top_missing || []).length > 0;

        var updated = document.getElementById('dashUpdatedAt');
        if (updated && data.updated_at) updated.textContent = data.updated_at;

        applyBars();
    }

    function refresh() {
        if (document.hidden) return;
        fetch('dashboard.php?action=stats', { cache: 'no-store' })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.success) render(data);
            })
            .catch(function () {});
    }

    applyBars();
    setInterval(refresh, REFRESH_MS);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) refresh();
    });
});
