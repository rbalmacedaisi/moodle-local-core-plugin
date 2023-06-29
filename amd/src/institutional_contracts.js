import * as Ajax from 'core/ajax';
import $ from 'jquery';

const createContractButton = $('#create-contract-button');
const removeContractButtons = $('.remove-contract');
const removeContractConfirmButton = $('#remove-contract-confirm-button');
const confirmContractDeletionModal = $('#confirmContractDeletionModal');

const viewUserDetailsButtons = $('.view-details-button');
const userInfoName = $('#user-info-name');
const userInfoEmail = $('#user-info-email');
const userInfoAvatar = $('#user-info-avatar');
const viewUserProfileButton = $('#view-profile-button');
const userInfoContractList = $('#user-info-contract-list');
const confirmContractUserDeletionModal = $('#confirmContractUserDeletionModal');
const confirmContractUserDeletionButton = $('#remove-contract-user-confirm-button');

const selectedUserInput = $('#selected-user')
const contractSelectInput = $('#contractlist')
const coursesSelectInput = $('#courselist')
const newContractUserInputs = [selectedUserInput,contractSelectInput,coursesSelectInput]
const addUserButton = $('#add-user-button')

const generateEnrolLinkModal = $('#generateEnrolLinkModal');
const enrolLinkContractListSelect = $('#enrolLinkContractList');
const enrolLinkCourseListSelect = $('#enrolLinkCourseList');
const enrolLinkInputs = [enrolLinkContractListSelect,enrolLinkCourseListSelect];
const enrolLinkGenerateButton = $('#generate-enrol-button');

const genetaredLinkInfoModal = $('#generatedLinkInfoModal')
const enrolLinkExpirationDateContainer = $('#enrolLinkExpirationDate');
const enrolLinkUrlContainer = $('#enrolLinkUrl');
const enrolUrlClipboardCopyButton = $('#enrolUrlClipboardCopy');

const bulkUserContractInput = $('#upload_massive_inst_users');
const bulkConfirmModal = $('#bulkConfirmModal');
const bulkConfirmModalButton = $('#bulkConfirmModalButton');
const bulkCancelModalButton = $('#bulkCancelModalButton');
const bulkReportModal = $('#bulkReportModal');
const bulkResultTableBody = $('#bulkResultTableBody');
const bulkReportContinueButton = $('#bulk-report-continue');

const contractSearch = $('#contract-search');
const contractUserSearch = $('#contract-user-search');
const contractApplyFilterButton = $('#contract-apply-filter-button');
const userContractApplyFilterButton =$('#user-contract-apply-filter-button');
const contractRemoveFilterButton =$('#contract-remove-filter-button');
const userContractRemoveFilterButton =$('#user-contract-remove-filter-button');

const contractIcon = window.location.href + '/theme/image.php?theme=soluttolmsadmin&amp;component=local_grupomakro_core&amp;image=t%2Fcontract';

const errorModal = $('#errorModal');
const errorModalContent = $('#error-modal-content');

let selectedInstitutionId;
let selectedContractId;
let selectedUser;
let selectedInstitutionContractUsers
let availableUsers;
let selectedUserToBeCreated
let institutionContracts
let selectedContractUserId
let generatedEnrolLinkUrl
let bulkFile;

export const init = (institutionId, institutionContractUsers,users,contractNames) => {
    selectedInstitutionContractUsers=institutionContractUsers;
    selectedInstitutionId = institutionId
    availableUsers=users
    institutionContracts=contractNames
    handleCreateContractButtonClick()
    handleRemoveContractButtonClick()
    handleRemoveContractConfirmButtonClick()
    handleRemoveContractUserConfirmButtonClick()
    handleViewUserDetailsButton()
    handleViewUserProfileButtonClick();
    handleDeleteUserContractLinkButtonClick();
    handleUserCreation();
    handleEnrolLinkGenerateButtonClick()
    handleEnrolUrlClipboardCopyButton()
    handleBulkContractUserInput()
    handleAddFilters()
    handleRemoveFilters()
};

const handleRemoveFilters = () => {
    contractRemoveFilterButton.click(()=>{
        const queryParams = new URLSearchParams(window.location.search);
        const contractUserFilter = queryParams.get('contractUserFilter')
        window.location.href = `/local/grupomakro_core/pages/institutionalcontracts.php?id=${selectedInstitutionId}${contractUserFilter? `&contractUserFilter=${contractUserFilter}` : ''}`
    })
    
    userContractRemoveFilterButton.click(()=>{
        const queryParams = new URLSearchParams(window.location.search);
        const contractFilter = queryParams.get('contractFilter')
        window.location.href = `/local/grupomakro_core/pages/institutionalcontracts.php?id=${selectedInstitutionId}${contractFilter? `&contractFilter=${contractFilter}` : ''}#users`
    })
}

const handleAddFilters = () => {
    contractApplyFilterButton.click(()=>{
        const queryParams = new URLSearchParams(window.location.search);
        const contractUserFilter = queryParams.get('contractUserFilter')
        window.location.href = `/local/grupomakro_core/pages/institutionalcontracts.php?id=${selectedInstitutionId}&contractFilter=${contractSearch.val()}${contractUserFilter? `&contractUserFilter=${contractUserFilter}` : ''}`
        
    })
    userContractApplyFilterButton.click(()=>{
        const queryParams = new URLSearchParams(window.location.search);
        const contractFilter = queryParams.get('contractFilter')
        window.location.href = `/local/grupomakro_core/pages/institutionalcontracts.php?id=${selectedInstitutionId}&contractUserFilter=${contractUserSearch.val()}${contractFilter? `&contractFilter=${contractFilter}` : ''}#users`
    })
}

const handleBulkContractUserInput = () => {
    bulkReportContinueButton.click(()=>{
        window.location.reload()
    })
    
    bulkUserContractInput.on('change',(event)=>{
        bulkFile = event.target.files[0];
        bulkConfirmModal.modal('show')
        console.log(bulkFile);
    })
    
    bulkConfirmModalButton.click(async()=>{
        const formData = new FormData();
        formData.append('file', bulkFile);
        formData.append('token', '33513bec0b3469194c7756c29bf9fb33');
        const fetchParams = {
            method: 'POST',
            body:formData
        }
        try{
            let bulkCSVUploadResponse = await window.fetch(`${window.location.href}/webservice/upload.php`, fetchParams)
            if (!bulkCSVUploadResponse.ok) {
                throw new Error('Request failed with status: ' + bulkCSVUploadResponse.status);
            }
            bulkCSVUploadResponse = await bulkCSVUploadResponse.json();
            bulkCSVUploadResponse = bulkCSVUploadResponse[0]
            const args = {
                contextId:bulkCSVUploadResponse.contextid,
                itemId:bulkCSVUploadResponse.itemid,
                filename:bulkCSVUploadResponse.filename
            }
            const bulkEnrolResponse =await Ajax.call([{
                methodname: 'local_grupomakro_bulk_create_contract_user',
                args
            }])[0];
            if(!bulkEnrolResponse.result)throw bulkEnrolResponse.message
            const bulkResults = JSON.parse(bulkEnrolResponse.result)
            console.log(bulkResults);
            bulkConfirmModal.modal('hide')
            bulkReportModal.modal('show');
            
            let bulkResultTableRowsHtmlString = ''
            bulkResults.errors.forEach(record => {
                bulkResultTableRowsHtmlString+=`<tr class="inserted-error">
                      <th scope="row">${record.index}</th>
                      <td>${record.error}</td>
                    </tr>`
            })
            
            bulkResultTableBody.html(bulkResultTableRowsHtmlString)
            
        }catch (error){
            errorModalContent.html(`<p class="text-center">${error}</p>`);
            errorModal.modal('show');
            console.error(error);
        }finally{
            return;
        }
    })
    
    bulkCancelModalButton.click(()=>{
        bulkConfirmModal.modal('hide')
        bulkFile=undefined;
    })

}

const handleEnrolUrlClipboardCopyButton = () => {
    enrolUrlClipboardCopyButton.click(()=>{
        window.navigator.clipboard.writeText(generatedEnrolLinkUrl)
        window.alert('Se ha copiado la url')
    })
}

const handleEnrolLinkGenerateButtonClick = () => {
    enrolLinkGenerateButton.click(()=>{
        // Check the select inputs and the time inputs
        const valid = enrolLinkInputs.every(input => {
            return input.get(0).reportValidity();
        });
        if (!valid) {
            return;
        }
        //
        const args = {
            contractId:enrolLinkContractListSelect.val(),
            courseId:enrolLinkCourseListSelect.val()
        };
        const promise = Ajax.call([{
            methodname: 'local_grupomakro_generate_contract_enrol_link',
            args
        }, ]);
        promise[0].done(function(response) {
            if(response.contractEnrolLink === '-1' ){
                errorModalContent.html(`<p class="text-center">${response.message}</p>`);
                errorModal.modal('show');
                return   
            }
            generateEnrolLinkModal.modal('hide');
            enrolLinkExpirationDateContainer.text(response.expirationDate);
            enrolLinkUrlContainer.text(response.contractEnrolLink);
            generatedEnrolLinkUrl = response.contractEnrolLink
            genetaredLinkInfoModal.modal('show');
        }).fail(function(error) {
            console.error(error)
        });
        
    })
}

const handleUserCreation = () => {
    addUserButton.click(()=>{
        selectedUserInput.get(0).setCustomValidity('');
        // Check the select inputs and the time inputs
        
        const valid = newContractUserInputs.every(input => {
            return input.get(0).reportValidity();
        });
        if (!valid) {
            return;
        }
        //
        
        if(!selectedUserToBeCreated) {
            selectedUserInput.get(0).setCustomValidity('Este usuario no existe.');
            selectedUserInput.get(0).reportValidity();
            return;
        }
        
        const args = {
            userId:selectedUserToBeCreated.id,
            contractId:contractSelectInput.val(),
            courseIds: coursesSelectInput.val().join(',') 
        };
        const promise = Ajax.call([{
            methodname: 'local_grupomakro_create_contract_user',
            args
        }, ]);
        promise[0].done(function(response) {
            if(!response.result){
                errorModalContent.html(`<p class="text-center">${response.message}</p>`);
                errorModal.modal('show');
            }
            const parsedResponse = JSON.parse(response.result)
            console.log(parsedResponse);
            if(!parsedResponse.success.length ){
                
                errorModalContent.html(`<p class="text-center">${parsedResponse.failure[0].message}</p>`);
                errorModal.modal('show');
                return   
            }
            window.location.reload()
        }).fail(function(error) {
            console.error(error)
        });
    })
    
    selectedUserInput.on('input',()=>{
        selectedUserToBeCreated = availableUsers.find(user=>user.username ===selectedUserInput.val())
        if(!selectedUserToBeCreated) {
            contractSelectInput.prop('disabled',true)
            return
        }
        contractSelectInput.removeAttr('disabled')
    })
    
}

const handleDeleteUserContractLinkButtonClick = ()=> {
    const deleteUserContractLinkButtons = $('.delete-contract-user-link')
    deleteUserContractLinkButtons.click(event=>{
        selectedContractUserId = event.currentTarget.attributes['contract-user-id'].value
        confirmContractUserDeletionModal.modal('show')
    })
    
    
}

const handleRemoveContractUserConfirmButtonClick = () => {
    confirmContractUserDeletionButton.click(()=>{
        if(!selectedContractUserId)return
        const args = {
            id:selectedContractUserId
        };
        const promise = Ajax.call([{
            methodname: 'local_grupomakro_delete_contract_user',
            args
        }, ]);
        promise[0].done(function(response) {
            if(response.deletedContractUserId === -1 ){
                confirmContractUserDeletionModal.modal('hide')
                errorModalContent.html(`<p class="text-center">${response.message}</p>`);
                errorModal.modal('show');
                return   
            }
            window.location.reload()
        }).fail(function(error) {
            console.error(error)
        });
    })
    
}

const handleViewUserProfileButtonClick = () =>{
    viewUserProfileButton.click(()=>{
        window.open(selectedUser.profileUrl,'blank')
    })
}

const handleCreateContractButtonClick = () => {
    createContractButton.click(()=>{
        window.location.href = `/local/grupomakro_core/pages/createcontractinstitutional.php?id=${selectedInstitutionId}`
    })
}

const handleRemoveContractButtonClick = () => {
    removeContractButtons.click(event=>{
        selectedContractId = event.currentTarget.attributes['contract-id'].value
    })
}

const handleRemoveContractConfirmButtonClick = ()=> {
    removeContractConfirmButton.click(()=>{
        const args = {
            id:selectedContractId,
        };
        const promise = Ajax.call([{
            methodname: 'local_grupomakro_delete_institution_contract',
            args
        }, ]);
        promise[0].done(function(response) {
            if(response.deletedInstitutionContractId === -1 ){
                confirmContractDeletionModal.modal('hide')
                errorModalContent.html(`<p class="text-center">${response.message}</p>`);
                errorModal.modal('show');
                return   
            }
            window.location.reload()
        }).fail(function(error) {
            console.error(error)
        });
    })
}

const handleViewUserDetailsButton = () => {
    viewUserDetailsButtons.click((event)=> {
        selectedUser = selectedInstitutionContractUsers[event.currentTarget.attributes['contract-user-id'].value]
        userInfoName.text(selectedUser.fullname)
        userInfoName.attr('href',selectedUser.profileUrl)
        userInfoEmail.text(selectedUser.email)
        userInfoAvatar.attr('src',selectedUser.avatar)
        
        let contractsHtmlString = ''
        selectedUser.contracts.forEach(contract=>{
            contractsHtmlString+=`
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <div class="contracicon d-flex align-items-center">
                    <img class="icon " alt="" aria-hidden="true" src="${contractIcon}">
                    <span class="ml-2">${contract.contractId}</span>
                    <span class="ml-2">${contract.courseName}</span>
                </div>
                <a href="#" class="text-secondary delete-contract-user-link" contract-user-id="${contract.id}">
                    <i class="fa fa-trash" style="font-size:20px"></i>
                </a>
            </li>`
        })
        
        userInfoContractList.html(contractsHtmlString)
        handleDeleteUserContractLinkButtonClick()
    })
}