document.addEventListener('DOMContentLoaded', function () {
    var form = document.querySelector('.logs-filters');
    if (form) {
        var fromEl = form.querySelector('#from');
        var toEl = form.querySelector('#to');
        var userEl = form.querySelector('#user');
        var today = form.dataset.today;
        var isDate = /^\d{4}-\d{2}-\d{2}$/;

        var addDays = function (value, days) {
            var parts = value.split('-');
            var d = new Date(Date.UTC(+parts[0], +parts[1] - 1, +parts[2] + days));
            return d.toISOString().slice(0, 10);
        };
        var lastFrom = addDays(today, -1);

        var setToLimit = function () {
            fromEl.max = lastFrom;
            toEl.max = today;
            if (fromEl.value && isDate.test(fromEl.value)) {
                toEl.min = addDays(fromEl.value, 1);
                if (toEl.value && toEl.value <= fromEl.value) {
                    toEl.value = '';
                }
            } else {
                toEl.removeAttribute('min');
            }
        };

        fromEl.addEventListener('input', setToLimit);
        fromEl.addEventListener('change', function () {
            if (fromEl.value > lastFrom) fromEl.value = lastFrom;
            setToLimit();
            if (toEl.value || !fromEl.value) form.submit();
        });

        toEl.addEventListener('change', function () {
            if (toEl.value && !isDate.test(toEl.value)) return;
            if (toEl.value > today) toEl.value = today;
            if (toEl.value && fromEl.value && toEl.value <= fromEl.value) toEl.value = addDays(fromEl.value, 1);
            form.submit();
        });

        userEl.addEventListener('change', function () {
            form.submit();
        });

        var clearBtn = form.querySelector('#logsClearBtn');
        var syncClear = function () {
            if (clearBtn) clearBtn.hidden = !(fromEl.value || toEl.value || userEl.value);
        };
        if (clearBtn) {
            clearBtn.addEventListener('click', function () {
                window.location.href = 'backup_logs.php';
            });
        }
        [fromEl, toEl, userEl].forEach(function (el) {
            el.addEventListener('input', syncClear);
            el.addEventListener('change', syncClear);
        });

        window.addEventListener('pageshow', function () {
            setToLimit();
            syncClear();
        });
        setToLimit();
        syncClear();
    }

    document.querySelectorAll('.backup-flash').forEach(function (flash) {
        setTimeout(function () {
            flash.classList.add('is-hiding');
            setTimeout(function () {
                flash.remove();
            }, 400);
        }, 10000);
    });

    var backupNowForm = document.querySelector('.backup-now-form');
    if (backupNowForm) {
        backupNowForm.addEventListener('submit', function () {
            var btn = document.getElementById('backupNowBtn');
            if (btn) {
                btn.disabled = true;
                btn.textContent = 'BACKING UP…';
            }
        });
    }

    var frequencyEl = document.getElementById('backupFrequency');
    var weekdayEl = document.getElementById('backupWeekday');
    var timeEl = document.getElementById('backupTime');
    var syncSchedule = function () {
        if (!frequencyEl) return;
        if (weekdayEl) weekdayEl.hidden = frequencyEl.value !== 'weekly';
        if (timeEl) timeEl.hidden = frequencyEl.value === 'off';
    };
    if (frequencyEl) {
        frequencyEl.addEventListener('change', syncSchedule);
        syncSchedule();
    }

    var logsWrap = document.querySelector('.logs-table-wrap');
    var limitLogRows = function () {
        if (!logsWrap) return;
        var rows = logsWrap.querySelectorAll('.logs-table > tbody > tr:not(.log-details-row)');
        if (rows.length <= 10) {
            logsWrap.classList.remove('is-scrollable');
            logsWrap.style.maxHeight = '';
            return;
        }
        logsWrap.classList.add('is-scrollable');
        logsWrap.style.maxHeight = '';
        var tenth = rows[9];
        var height = tenth.getBoundingClientRect().bottom - logsWrap.getBoundingClientRect().top + logsWrap.scrollTop;
        logsWrap.style.maxHeight = Math.ceil(height) + 'px';
    };
    limitLogRows();
    window.addEventListener('resize', limitLogRows);

    document.querySelectorAll('.log-details-btn[data-target]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var row = document.getElementById(btn.dataset.target);
            if (!row) return;
            var open = row.hidden;
            row.hidden = !open;
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            btn.classList.toggle('is-open', open);
        });
    });
});