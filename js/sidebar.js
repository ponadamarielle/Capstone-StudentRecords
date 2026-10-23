(function () {
    var KEY = 'pupSidebarCollapsed';
    var root = document.documentElement;

    function read() {
        try { return localStorage.getItem(KEY) === '1'; } catch (e) { return false; }
    }
    function save(value) {
        try { localStorage.setItem(KEY, value ? '1' : '0'); } catch (e) {}
    }
    function sync(btn) {
        var collapsed = root.classList.contains('sidebar-collapsed');
        if (!btn) return;
        var label = collapsed ? 'Expand sidebar' : 'Collapse sidebar';
        btn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        btn.setAttribute('aria-label', label);
        btn.setAttribute('title', label);
    }

    if (read()) root.classList.add('sidebar-collapsed');

    document.addEventListener('DOMContentLoaded', function () {
        var btn = document.getElementById('sidebarCollapseBtn');
        sync(btn);
        if (btn) {
            btn.addEventListener('click', function () {
                root.classList.add('sidebar-animate');
                var collapsed = root.classList.toggle('sidebar-collapsed');
                save(collapsed);
                sync(btn);
                window.dispatchEvent(new Event('resize'));
            });
        }
    });
})();
