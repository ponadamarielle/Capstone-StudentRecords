document.addEventListener('DOMContentLoaded', function () {
    const courseSelect      = document.getElementById('courseFilterSelect');
    const yearSectionSelect = document.getElementById('yearSectionFilterSelect');
    const filterForm        = document.getElementById('directoryFilterOnlyForm');

    function goToFilteredUrl() {
        if (!filterForm) return;

        const searchValue      = (filterForm.elements['search'] || {}).value || '';
        const courseValue      = (courseSelect || {}).value || '';
        const yearSectionValue = (yearSectionSelect || {}).value || '';

        const params = new URLSearchParams();
        if (searchValue !== '') params.set('search', searchValue);
        if (courseValue !== '') params.set('course', courseValue);
        if (yearSectionValue !== '') params.set('year_section', yearSectionValue);

        const query = params.toString();
        window.location.href = 'student_directory.php' + (query ? '?' + query : '');
    }

    if (courseSelect) {
        courseSelect.addEventListener('change', goToFilteredUrl);
    }

    if (yearSectionSelect) {
        yearSectionSelect.addEventListener('change', goToFilteredUrl);
    }
});