document.addEventListener('DOMContentLoaded', function () {
    var btn = document.getElementById('classReportBtn');
    var modalEl = document.getElementById('classReportModal');
    if (!btn || !modalEl) return;

    var frame = document.getElementById('classReportFrame');
    var status = document.getElementById('classReportStatus');
    var download = document.getElementById('classReportDownload');
    var excel = document.getElementById('classReportExcel');
    var subtitle = document.getElementById('classReportSubtitle');
    var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
    var noPdfViewer = navigator.pdfViewerEnabled === false;
    var fallback = null;
    var fallbackLink = null;
    if (noPdfViewer) {
        fallback = document.createElement('div');
        fallback.className = 'text-center text-muted py-5';
        var fallbackText = document.createElement('p');
        fallbackText.textContent = 'PDF preview is not available on this device. Tap Open PDF to view it.';
        fallbackLink = document.createElement('a');
        fallbackLink.className = 'btn btn-sm btn-outline-secondary';
        fallbackLink.target = '_blank';
        fallbackLink.rel = 'noopener';
        fallbackLink.textContent = 'Open PDF';
        fallback.appendChild(fallbackText);
        fallback.appendChild(fallbackLink);
        frame.parentNode.insertBefore(fallback, frame.nextSibling);
        frame.style.display = 'none';
    }

    function selectedText(id, fallback) {
        var select = document.getElementById(id);
        if (!select || !select.value) return fallback;
        var option = select.options[select.selectedIndex];
        return option ? option.textContent.trim() : fallback;
    }

    btn.addEventListener('click', function () {
        var current = new URLSearchParams(window.location.search);
        var params = new URLSearchParams();
        ['course', 'year_section', 'status', 'type'].forEach(function (key) {
            var value = current.get(key);
            if (value) params.set(key, value);
        });
        var url = 'class_report.php' + (params.toString() ? '?' + params.toString() : '');
        var downloadParams = new URLSearchParams(params);
        downloadParams.set('download', '1');

        subtitle.textContent = [
            selectedText('courseFilterSelect', 'All courses'),
            params.get('year_section') ? 'Section ' + params.get('year_section') : 'All sections',
            selectedText('statusFilterSelect', 'All status'),
            selectedText('typeFilterSelect', 'All types')
        ].join(' · ');

        download.href = 'class_report.php?' + downloadParams.toString();
        var excelParams = new URLSearchParams(params);
        excelParams.set('format', 'xlsx');
        if (excel) excel.href = 'class_report.php?' + excelParams.toString();
        if (noPdfViewer) {
            fallbackLink.href = url;
            status.hidden = true;
            modal.show();
            return;
        }
        status.hidden = false;
        frame.classList.add('is-loading');
        frame.src = url;
        modal.show();
    });

    frame.addEventListener('load', function () {
        if (!frame.getAttribute('src')) return;
        status.hidden = true;
        frame.classList.remove('is-loading');
    });

    modalEl.addEventListener('hidden.bs.modal', function () {
        frame.removeAttribute('src');
        status.hidden = true;
        frame.classList.remove('is-loading');
    });
});