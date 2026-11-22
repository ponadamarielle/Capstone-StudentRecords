document.addEventListener('click', async function (e) {
    const link = e.target.closest('a.backup-latest-btn, a.backup-download-btn');
    if (!link || !window.showSaveFilePicker || !window.isSecureContext) return;
    e.preventDefault();
    if (link.dataset.busy === '1') return;

    const fileName = link.dataset.fileName || 'pup_erecords_backup.zip';
    const isZip = /\.zip$/i.test(fileName);
    let handle;
    try {
        handle = await window.showSaveFilePicker({
            suggestedName: fileName,
            types: [{
                description: 'PUP eRecords backup',
                accept: isZip ? { 'application/zip': ['.zip'] } : { 'application/sql': ['.sql'] },
            }],
        });
    } catch (err) {
        if (err && err.name === 'AbortError') return;
        window.location = link.href;
        return;
    }

    const label = link.innerHTML;
    link.dataset.busy = '1';
    link.classList.add('disabled');
    link.innerHTML = '<span class="spinner-border spinner-border-sm" role="status"></span> SAVING…';
    let writable = null;
    try {
        const res = await fetch(link.href, { credentials: 'same-origin' });
        const type = res.headers.get('Content-Type') || '';
        if (!res.ok || type.indexOf('text/html') !== -1) {
            window.location = 'backup_logs.php#backups';
            return;
        }
        writable = await handle.createWritable();
        await res.body.pipeTo(writable);
        writable = null;
        link.innerHTML = '<i class="bi bi-check-lg"></i> SAVED';
        setTimeout(function () { link.innerHTML = label; }, 2500);
    } catch (err) {
        if (writable) {
            try { await writable.abort(); } catch (ignore) {}
        }
        link.innerHTML = label;
        window.location = link.href;
        return;
    } finally {
        link.dataset.busy = '';
        link.classList.remove('disabled');
    }
});
