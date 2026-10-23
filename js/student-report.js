document.addEventListener('DOMContentLoaded', function () {
    const reportBtn = document.getElementById('generateReportBtn');
    if (!reportBtn || !window.bootstrap) return;
    const studentId = reportBtn.dataset.studentId;
    const REPORT_URL = 'student_report.php?id=' + encodeURIComponent(studentId);

    function el(tag, className, text) {
        const node = document.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;
        return node;
    }

    const modalEl = el('div', 'modal fade report-modal');
    modalEl.id = 'studentReportModal';
    modalEl.tabIndex = -1;
    modalEl.setAttribute('aria-hidden', 'true');
    const dialog = el('div', 'modal-dialog modal-dialog-centered modal-xl');
    const content = el('div', 'modal-content');

    const header = el('div', 'modal-header report-header');
    const heading = el('div', 'report-heading');
    const headingIcon = el('span', 'report-heading-icon');
    headingIcon.appendChild(el('i', 'bi bi-file-earmark-text'));
    const headingText = el('div', 'report-heading-text');
    headingText.appendChild(el('h5', 'modal-title report-title', 'Student record report'));
    headingText.appendChild(el('p', 'report-subtitle', 'Preview the report. Use the viewer\'s download or print button to save a copy.'));
    heading.appendChild(headingIcon);
    heading.appendChild(headingText);
    header.appendChild(heading);

    const body = el('div', 'modal-body');
    const status = el('div', 'report-status');
    const spinner = el('span', 'spinner-border spinner-border-sm');
    spinner.setAttribute('role', 'status');
    status.appendChild(spinner);
    status.appendChild(el('span', '', 'Generating the report... please wait.'));
    const frame = el('iframe', 'report-frame');
    frame.title = 'Student report preview';
    body.appendChild(status);
    body.appendChild(frame);

    const footer = el('div', 'modal-footer report-footer');
    const closeBtn = el('button', 'btn report-secondary-btn', 'CLOSE');
    closeBtn.type = 'button';
    closeBtn.setAttribute('data-bs-dismiss', 'modal');
    footer.appendChild(closeBtn);

    content.appendChild(header);
    content.appendChild(body);
    content.appendChild(footer);
    dialog.appendChild(content);
    modalEl.appendChild(dialog);
    document.body.appendChild(modalEl);

    const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

    let loadTimer = null;

    function loadReport() {
        status.hidden = false;
        frame.classList.add('is-loading');
        clearTimeout(loadTimer);
        loadTimer = setTimeout(function () {
            status.hidden = true;
            frame.classList.remove('is-loading');
        }, 15000);
        frame.src = REPORT_URL + '&t=' + Date.now() + '#view=Fit';
    }

    frame.addEventListener('load', function () {
        if (!frame.getAttribute('src')) return;
        status.hidden = true;
        frame.classList.remove('is-loading');
    });

    reportBtn.addEventListener('click', function () {
        modal.show();
        loadReport();
    });
    modalEl.addEventListener('hidden.bs.modal', function () {
        frame.removeAttribute('src');
    });
});