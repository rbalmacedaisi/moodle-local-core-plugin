import * as Ajax from 'core/ajax';
import $ from 'jquery';

const errorModal = $('#errorModal');
const errorModalContent = $('#error-modal-content');

const documentCheckInputHolder = $('#document-check-input');
const userCheckIdentificationNumberInput = $('#userCheckIdentificationNumber');
const enrolButton = $('#enrolButton');
const userNotFoundModal = $('#userNotFoundModal');
const enrolCreateAccountButton = $('#enrolCreateAccountButton');

const createAccountInputsHolder = $('#create-user-inputs');
const createUserIdentificationNumberInput = $('#userIdentificationNumber');
const createUserFirstNameInput = $('#userFirstName');
const createUserLastNameInput = $('#userLastName');
const createUserEmailInput = $('#userEmail');

const createUserInputs = [createUserIdentificationNumberInput,createUserFirstNameInput,createUserLastNameInput,createUserEmailInput];

let enrolCourseId,enrolContractId,creatingAccount = false;

const webserviceUrl = 'https://grupomakro-dev.soluttolabs.com/webservice/rest/server.php?wstoken=33513bec0b3469194c7756c29bf9fb33&moodlewsrestformat=json&wsfunction=';
const fetchParams = {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
    }

export const init = async (courseId,contractId) => {
    [enrolCourseId,enrolContractId] = [courseId,contractId];
    handleEnrolButtonClick();
    handleEnrolCreateAccountButtonClick()
};
const handleEnrolCreateAccountButtonClick = () => {
    enrolCreateAccountButton.click(()=>{
        creatingAccount = true;
        documentCheckInputHolder.hide();
        userNotFoundModal.modal('hide');
        createAccountInputsHolder.show();
    })
}

const handleEnrolButtonClick = () => {
    enrolButton.click(async ()=>{
        if(!creatingAccount){
            if(!userCheckIdentificationNumberInput.get(0).reportValidity()) return;
            const params = new URLSearchParams();
            params.append('field', 'username');
            params.append('values[0]', userCheckIdentificationNumberInput.val());
            try {
                // First,check if the user exists
                let response = await window.fetch(webserviceUrl+'core_user_get_users_by_field&'+params, fetchParams)
                if (!response.ok) {
                  throw new Error('Request failed with status: ' + response.status);
                }
                response = await response.json();
                if (!response.length) {
                    userNotFoundModal.modal('show');
                    return;
                }
                return enrolContractUser(response[0].id);
                
            
            } catch (error) {
                errorModalContent.html(`<p class="text-center">${error.message}</p>`);
                errorModal.modal('show');
                console.error(error);
                
            } finally{
                return;
            }
        }
        
        // Check the create user inputs
        const valid = createUserInputs.every(input => {
            return input.get(0).reportValidity();
        });
        if (!valid) {
            return;
        }
        const args = {
            users: [{
                username:createUserIdentificationNumberInput.val(),
                firstname:createUserFirstNameInput.val(),
                lastname:createUserLastNameInput.val(),
                email:createUserEmailInput.val()
            }]
            
        };
        try {
            const response = await Ajax.call([
                {
                    methodname: 'core_user_create_users',
                    args,
                },
            ])[0];
            console.log(response)
        } catch (error){
            errorModalContent.html(`<p class="text-center">${error.message}</p>`);
            errorModal.modal('show');
            console.error(error);
        }
        //
    })
} 

const enrolContractUser = async (userId) => {
    const params = new URLSearchParams();
    params.append('userId', userId);
    params.append('contractId', enrolContractId);
    params.append('courseIds', enrolCourseId);
    try{
        let response = await window.fetch(webserviceUrl+'local_grupomakro_create_contract_user&'+params, fetchParams)
        if (!response.ok) {
            throw new Error('Request failed with status: ' + response.status);
        }
        response = await response.json();
        if(response.contractUserId ===-1)throw response.message;
        console.log('bien');
        
    }catch (error){
        errorModalContent.html(`<p class="text-center">${error}</p>`);
        errorModal.modal('show');
        console.error(error);
    }finally{
        return;
    }
    
}
