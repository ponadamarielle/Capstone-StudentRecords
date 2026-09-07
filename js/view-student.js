document.addEventListener('DOMContentLoaded', () => {
    const documentList        = document.getElementById('documentList');
    const replaceDocumentInput = document.getElementById('replaceDocumentInput');
    const newDocumentInput     = document.getElementById('newDocumentInput');
    const uploadNewRecordBtn   = document.getElementById('uploadNewRecordBtn');

    let activeRowForReplace = null;

    documentList.addEventListener('click', (e) => {
        const deleteBtn = e.target.closest('.document-delete-btn');
        if (!deleteBtn) return;

        const row = deleteBtn.closest('.document-row');
        const documentId = row.dataset.documentId;

        if (!confirm('Delete this document? This cannot be undone.')) return;

        const formData = new FormData();
        formData.append('document_id', documentId);

        fetch('view_student.php?action=delete_document_record', {
            method: 'POST',
            body: formData,
        })
            .then((res) => res.json())
            .then((data) => {
                if (data.success) {
                    row.remove();
                    if (!documentList.querySelector('.document-row')) {
                        documentList.insertAdjacentHTML(
                            'beforeend',
                            '<div class="text-muted document-empty-state">No documents uploaded yet.</div>'
                        );
                    }
                } else {
                    alert(data.message || 'Failed to delete document.');
                }
            })
            .catch(() => alert('Something went wrong deleting the document.'));
    });

    documentList.addEventListener('click', (e) => {
        const changeBtn = e.target.closest('.document-change-btn');
        if (!changeBtn) return;

        activeRowForReplace = changeBtn.closest('.document-row');
        replaceDocumentInput.click();
    });

    replaceDocumentInput.addEventListener('change', () => {
        if (!replaceDocumentInput.files.length || !activeRowForReplace) return;

        const documentId = activeRowForReplace.dataset.documentId;
        const formData = new FormData();
        formData.append('document_id', documentId);
        formData.append('document', replaceDocumentInput.files[0]);

        fetch('view_student.php?action=replace_document', {
            method: 'POST',
            body: formData,
        })
            .then((res) => res.json())
            .then((data) => {
                if (data.success) {
                    const nameEl = activeRowForReplace.querySelector('.document-name');
                    nameEl.textContent = data.file_name;

                    const viewLink = activeRowForReplace.querySelector('a[title="View file"]');
                    const downloadLink = activeRowForReplace.querySelector('a[title="Download file"]');
                    viewLink.href = data.file_path;
                    downloadLink.href = data.file_path;
                } else {
                    alert(data.message || 'Failed to replace document.');
                }
            })
            .catch(() => alert('Something went wrong replacing the document.'))
            .finally(() => {
                replaceDocumentInput.value = '';
                activeRowForReplace = null;
            });
    });

    uploadNewRecordBtn.addEventListener('click', () => {
        newDocumentInput.click();
    });

    newDocumentInput.addEventListener('change', () => {
        if (!newDocumentInput.files.length) return;

        const formData = new FormData();
        formData.append('student_id', STUDENT_ID);
        formData.append('document', newDocumentInput.files[0]);

        fetch('view_student.php?action=add_document', {
            method: 'POST',
            body: formData,
        })
            .then((res) => res.json())
            .then((data) => {
                if (data.success) {
                    const emptyState = documentList.querySelector('.document-empty-state');
                    if (emptyState) emptyState.remove();

                    const row = document.createElement('div');
                    row.className = 'document-row';
                    row.dataset.documentId = data.document_id;
                    row.innerHTML = `
                        <span class="document-name">${data.file_name}</span>
                        <div class="document-actions">
                            <a href="${data.file_path}" target="_blank" class="btn btn-sm btn-outline-secondary" title="View file"><i class="bi bi-eye"></i></a>
                            <a href="${data.file_path}" download class="btn btn-sm btn-outline-secondary" title="Download file"><i class="bi bi-download"></i></a>
                            <button type="button" class="btn btn-sm btn-outline-secondary document-change-btn" title="Change file"><i class="bi bi-arrow-repeat"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-danger document-delete-btn" title="Delete file"><i class="bi bi-trash"></i></button>
                        </div>
                    `;
                    documentList.appendChild(row);
                } else {
                    alert(data.message || 'Failed to upload document.');
                }
            })
            .catch(() => alert('Something went wrong uploading the document.'))
            .finally(() => {
                newDocumentInput.value = '';
            });
    });
});
