document.addEventListener('DOMContentLoaded', function () {
    var yearSelect = document.getElementById('syEndYear');
    var dateInput = document.getElementById('syCommencementEnd');
    if (!yearSelect || !dateInput) return;

    function syncDate() {
        var year = parseInt(yearSelect.value, 10);
        if (!year) return;
        dateInput.min = year + '-01-01';
        dateInput.max = year + '-12-31';
        var option = yearSelect.options[yearSelect.selectedIndex];
        var date = option ? option.getAttribute('data-date') : '';
        if (date && date.indexOf(String(year)) === 0) {
            dateInput.value = date;
        } else if (dateInput.value.indexOf(String(year)) !== 0) {
            dateInput.value = '';
        }
    }

    yearSelect.addEventListener('change', syncDate);
    syncDate();
});