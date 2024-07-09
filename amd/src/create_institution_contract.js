import * as Ajax from 'core/ajax';
import $ from 'jquery';

const contractNumberInput = $('#contractnumber');
const startDateInput = $('#startdate');
const endDateInput = $('#enddate');
const budgetInput = $('#budget');
const billingConditionInput = $('#billing_condition');
const saveContractButton = $('#save-contract-button');
const cancelContractCreationButton = $('#cancel-contract-creation-button');

const contractInputs = [contractNumberInput, startDateInput, endDateInput, budgetInput, billingConditionInput];

const errorModal = $('#errorModal');
const errorModalContent = $('#error-modal-content');

let selectedInstitutionId;

export const init = (institutionId) => {
    selectedInstitutionId = institutionId;
    handleSaveContractButtonClick();
    handleBudgetInput();
    handleContractCreationButtonClick();
};

const handleSaveContractButtonClick = () => {
    saveContractButton.click(()=>{
        endDateInput.get(0).setCustomValidity('');
        // Check the select inputs and the time inputs
        const valid = contractInputs.every(input => {
            return input.get(0).reportValidity();
        });
        if (!valid) {
            return;
        }
        //

        // Check if the init time is less than the end time of the class
        if (startDateInput.val() >= endDateInput.val()) {
            endDateInput.get(0).setCustomValidity('La fecha de finalización debe ser mayor a la hora de inicio.');
            endDateInput.get(0).reportValidity();
            return;
        }
        //

        const args = {
            institutionId: selectedInstitutionId,
            contractId: contractNumberInput.val(),
            initDate: startDateInput.val(),
            expectedEndDate: endDateInput.val(),
            budget: budgetInput.val(),
            billingCondition: billingConditionInput.val(),
        };

        const promise = Ajax.call([{
            methodname: 'local_grupomakro_create_institution_contract',
            args
        }]);
        promise[0].done(function(response) {
            if (response.institutionContractId === -1) {
                errorModalContent.html(`<p class="text-center">${response.message}</p>`);
                errorModal.modal('show');
                return;
            }
            window.location.href = `/local/grupomakro_core/pages/institutionalcontracts.php?id=${selectedInstitutionId}`;
        }).fail(function(error) {
            window.alert(error);
        });
    });
};

const handleBudgetInput = () => {
    budgetInput.on('input', function() {
      var value = $(this).val().replace(/[^\d]/g, ''); // Remove non-digit characters
      value = value.replace(/\B(?=(\d{3})+(?!\d))/g, '.'); // Add dots as thousands separators
      $(this).val(value); // Set the formatted value back to the input
    });
};

const handleContractCreationButtonClick = ()=>{
    cancelContractCreationButton.click(()=>{
        window.location.href = `/local/grupomakro_core/pages/institutionalcontracts.php?id=${selectedInstitutionId}`;
    });

};

