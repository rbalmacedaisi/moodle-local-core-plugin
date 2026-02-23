import * as Ajax from 'core/ajax';
import $ from 'jquery';

const confirmDeleteButton = $('#confirmDeleteButton');

let selectedClassIds = [];

export const init = () => {
    handleViewToggle();
    handleSelectAll();
    handleCheckboxChange();
    handleDeleteClass();
    handleBulkDeleteClick();
    handleConfirmDeleteClass();
};

const handleViewToggle = () => {
    const gridBtn = $('#btn-grid-view');
    const listBtn = $('#btn-list-view');
    const gridView = $('#class-grid-view');
    const listView = $('#class-list-view');

    // Check localStorage for saved preference
    const savedView = localStorage.getItem('classManagementView') || 'grid';

    const setView = (viewType) => {
        if (viewType === 'grid') {
            gridBtn.addClass('active');
            listBtn.removeClass('active');
            gridView.removeClass('d-none');
            listView.addClass('d-none');
            localStorage.setItem('classManagementView', 'grid');
        } else {
            listBtn.addClass('active');
            gridBtn.removeClass('active');
            listView.removeClass('d-none');
            gridView.addClass('d-none');
            localStorage.setItem('classManagementView', 'list');
        }
    };

    // Apply saved setting
    setView(savedView);

    gridBtn.on('click', () => setView('grid'));
    listBtn.on('click', () => setView('list'));
};

const handleSelectAll = () => {
    $('#selectAllClasses').on('change', (event) => {
        const isChecked = $(event.target).is(':checked');
        $('.class-checkbox').prop('checked', isChecked);
        updateBulkDeleteButton();
    });
};

const handleCheckboxChange = () => {
    $('.class-checkbox').on('change', () => {
        updateBulkDeleteButton();
        if ($('.class-checkbox').length === $('.class-checkbox:checked').length) {
            $('#selectAllClasses').prop('checked', true);
        } else {
            $('#selectAllClasses').prop('checked', false);
        }
    });
};

const updateBulkDeleteButton = () => {
    selectedClassIds = [];
    $('.class-checkbox:checked').each((index, element) => {
        selectedClassIds.push($(element).val());
    });

    if (selectedClassIds.length > 0) {
        $('#bulkDeleteButton').removeClass('d-none');
    } else {
        $('#bulkDeleteButton').addClass('d-none');
    }
};

const handleBulkDeleteClick = () => {
    $('#bulkDeleteButton').on('click', () => {
        // IDs are already populated via updateBulkDeleteButton
    });
};

const handleDeleteClass = () => {
    $('.deleteButton').click(event => {
        const singleClassId = event.currentTarget.attributes['class-id'].value;
        selectedClassIds = [singleClassId];
    });
};

const handleConfirmDeleteClass = () => {
    confirmDeleteButton.click(() => {
        if (selectedClassIds.length === 0) return;

        const requests = selectedClassIds.map(id => ({
            methodname: 'local_grupomakro_delete_class',
            args: { id: id }
        }));

        const promise = Ajax.call(requests);
        $.when.apply($, promise).done(function () {
            window.location.href = '/local/grupomakro_core/pages/classmanagement.php';
        }).fail(function (error) {
            window.console.error(error);
        });
    });
};