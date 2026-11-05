document.addEventListener('DOMContentLoaded', function () {
    var item = document.querySelector('.doc-sort-item');
    var list = document.getElementById('documentList');
    if (!item || !list) return;

    var tabs = Array.prototype.slice.call(item.querySelectorAll('.doc-sort-tab'));
    Array.prototype.slice.call(list.querySelectorAll('.requirement-view-row')).forEach(function (row, index) {
        row.dataset.sortIndex = index;
    });

    var docsTab = document.getElementById('view-docs-tab');
    function syncVisibility() {
        item.hidden = !(docsTab && docsTab.classList.contains('active'));
    }
    document.querySelectorAll('#viewTabs [data-bs-toggle="tab"]').forEach(function (tab) {
        tab.addEventListener('shown.bs.tab', syncVisibility);
    });

    function compare(mode) {
        return function (a, b) {
            var nameA = (a.dataset.sortName || '').toLowerCase();
            var nameB = (b.dataset.sortName || '').toLowerCase();
            var timeA = parseInt(a.dataset.uploaded || '0', 10);
            var timeB = parseInt(b.dataset.uploaded || '0', 10);
            var order = parseInt(a.dataset.sortIndex, 10) - parseInt(b.dataset.sortIndex, 10);
            if (mode === 'name_asc') return nameA.localeCompare(nameB) || order;
            if (mode === 'newest') return (timeB - timeA) || order;
            if (mode === 'oldest') return (timeA - timeB) || order;
            return order;
        };
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (t) {
                var selected = t === tab;
                t.classList.toggle('active', selected);
                t.setAttribute('aria-pressed', selected ? 'true' : 'false');
            });
            Array.prototype.slice.call(list.querySelectorAll('.requirement-view-row'))
                .sort(compare(tab.dataset.sort))
                .forEach(function (row) { list.appendChild(row); });
        });
    });

    syncVisibility();
});