(function () {
    var checking = false;
    var stopped = false;

    async function checkSession() {
        if (checking || stopped) return;
        checking = true;
        try {
            var res = await fetch('session_check.php', { credentials: 'same-origin', cache: 'no-store' });
            if (res.status === 401) {
                var data = {};
                try { data = await res.json(); } catch (e) { }
                stopped = true;
                window.location.href = data.redirect || '../signin.php';
            }
        } catch (e) {
        } finally {
            checking = false;
        }
    }

    setInterval(checkSession, 15000);
    document.addEventListener('visibilitychange', function () {
        if (!document.hidden) checkSession();
    });
    window.addEventListener('focus', checkSession);
})();
