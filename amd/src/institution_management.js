import * as Ajax from 'core/ajax';
import $ from 'jquery';

const createInstitutionNameInput = $('#create_name_institution');
const createInstitutionIdInput = $('#create_id_institution');
const saveInstitutionButton = $('#saveInstitutionButton');
const updateInstitutionNameInput = $('#update_name_institution');
const updateInstitutionIdInput = $('#update_id_institution');
const updateInstitutionButton = $('#updateInstitutionButton');
const updateInstitutionModal = $('#updateInstitutionModalLong');
const deleteInstitutionModal = $('#deleteInstitutionModalLong');
const editInstitutionButtons = $('.edit-institution-button');
const deleteInstitutionButtons = $('.delete-institution-button');
const viewInstitutionButtons = $('.view-institution');
const confirmDeleteInstitutionButton = $('#confirmDeleteInstitutionButton');

const errorModal = $('#errorModal');
const errorModalContent = $('#error-modal-content');

const createInputs = [createInstitutionNameInput, createInstitutionIdInput];
const updateInputs = [updateInstitutionNameInput, updateInstitutionIdInput];

let institutions;
let institutionId;

export const init = (institutionList) => {
    institutions = institutionList;
    handleSaveButton();
    handleEditButton();
    handleUpdateButton();
    handleDeleteButton();
    handleConfirmDeletionButton();
    handleViewInstitutionButton();
};

const handleViewInstitutionButton = () => {
    viewInstitutionButtons.click((event)=>{
        institutionId = event.currentTarget.attributes['institution-id'].value;
        window.location.href = `/local/grupomakro_core/pages/institutionalcontracts.php?id=${institutionId}`;
    });
};

const handleConfirmDeletionButton = () => {
    confirmDeleteInstitutionButton.click(()=>{
        const args = {
            id: institutionId,
        };
        const promise = Ajax.call([{
            methodname: 'local_grupomakro_delete_institution',
            args
        }]);
        promise[0].done(function(response) {
            if (response.institutionId === -1) {
                deleteInstitutionModal.modal('hide');
                errorModalContent.html(`<p class="text-center">${response.message}</p>`);
                errorModal.modal('show');
                return;
            }
            window.location.href = '/local/grupomakro_core/pages/institutionmanagement.php';
        }).fail(function(error) {
            window.alert(error);
        });
    });

};


const handleDeleteButton = () => {
    deleteInstitutionButtons.click((event)=>{
        institutionId = event.currentTarget.attributes['institution-id'].value;
        deleteInstitutionModal.modal('show');
    });

};

const handleUpdateButton = () => {
    updateInstitutionButton.click(()=>{
         // Check the select inputs and the time inputs
        const valid = updateInputs.every(input => {
            return input.get(0).reportValidity();
        });
        if (!valid) {
            return;
        }
        //
        const args = {
            id: institutionId,
            name: updateInstitutionNameInput.val(),
            institutionId: updateInstitutionIdInput.val()

        };
        const promise = Ajax.call([{
            methodname: 'local_grupomakro_update_institution',
            args
        }]);
        promise[0].done(function(response) {
            if (response.institutionId === -1) {
                errorModalContent.html(`<p class="text-center">${response.message}</p>`);
                errorModal.modal('show');
                return;
            }
            window.location.href = '/local/grupomakro_core/pages/institutionmanagement.php';
        }).fail(function(error) {
            window.alert(error);
        });
    });

};

const handleEditButton = () => {
    editInstitutionButtons.click((event)=>{
        institutionId = event.currentTarget.attributes['institution-id'].value;
        const selectedInstitution = institutions.find(institution => institutionId === institution.id);
        updateInstitutionNameInput.val(selectedInstitution.name);
        updateInstitutionIdInput.val(selectedInstitution.institutionid);
        updateInstitutionModal.modal('show');
    });

};

const handleSaveButton = () => {
    saveInstitutionButton.click(()=> {
        // Check the select inputs and the time inputs
        const valid = createInputs.every(input => {
            return input.get(0).reportValidity();
        });
        if (!valid) {
            return;
        }
        //

        const args = {
            name: createInstitutionNameInput.val(),
            institutionId: createInstitutionIdInput.val()

        };
        const promise = Ajax.call([{
            methodname: 'local_grupomakro_create_institution',
            args
        }]);
        promise[0].done(function(response) {
            if (response.institutionId === -1) {
                errorModalContent.html(`<p class="text-center">${response.message}</p>`);
                errorModal.modal('show');
                return;
            }
            window.location.href = '/local/grupomakro_core/pages/institutionmanagement.php';
        }).fail(function(error) {
            window.alert(error);
        });
    });
};


