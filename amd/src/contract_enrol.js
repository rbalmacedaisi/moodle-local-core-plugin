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
let webserviceUrl;

const fetchParams = {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
    }

export const init = async (courseId,contractId,wsUrl) => {
    webserviceUrl = wsUrl;
    [enrolCourseId,enrolContractId] = [courseId,contractId];
    handleEnrolButtonClick();
    handleEnrolCreateAccountButtonClick()
};

const handleEnrolCreateAccountButtonClick = () => {
    enrolCreateAccountButton.click(()=>{
        creatingAccount = true;
        documentCheckInputHolder.hide();
        createUserIdentificationNumberInput.val(userCheckIdentificationNumberInput.val())
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
        const params = new URLSearchParams();
        params.append('username', createUserIdentificationNumberInput.val());
        params.append('firstname', createUserFirstNameInput.val());
        params.append('lastname', createUserLastNameInput.val());
        params.append('email', createUserEmailInput.val());
        params.append('contractId',enrolContractId);
        params.append('courseId', enrolCourseId);
        try{
        let response = await window.fetch(webserviceUrl+'local_grupomakro_create_student_user&'+params, fetchParams)
            if (!response.ok) {
                throw new Error('Request failed with status: ' + response.status);
            }
            response = await response.json();
            if(response.contractEnrolResult ===-1) throw response.message;
            setTimeout(()=>{
                window.location.href = `https://students-lxp-dev.soluttolabs.com/login`
            },5000)
            
        }catch (error){
            errorModalContent.html(`<p class="text-center">${error}</p>`);
            errorModal.modal('show');
            console.error(error);
        }finally{
            return;
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
        if(!response.result) throw response.message
        
        const parsedResponse = JSON.parse(response.result)
        
        if(!parsedResponse.success.length )throw parsedResponse.failure[0].message
        
        setTimeout(()=>{
            window.location.href = `https://students-lxp-dev.soluttolabs.com/login`
        },5000)
        
    }catch (error){
        errorModalContent.html(`<p class="text-center">${error}</p>`);
        errorModal.modal('show');
    }finally{
        return;
    }
    
}
