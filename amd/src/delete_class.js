import * as Ajax from 'core/ajax';
import $ from 'jquery';

const deleteButtons = $('.deleteButton');
const confirmDeleteButton = $('#confirmDeleteButton');

let classId;
export const init = () => {
    handleDeleteClass();
    handleConfirmDeleteClass();
};

const handleDeleteClass = () => {
    deleteButtons.click(event=>{
         classId = event.currentTarget.attributes['class-id'].value;
    });
};

const handleConfirmDeleteClass = () =>{

    confirmDeleteButton.click(()=>{
        const args = {
            id: classId
        };
        const promise = Ajax.call([{
            methodname: 'local_grupomakro_delete_class',
            args
        }]);
        promise[0].done(function(response) {
            window.console.log(response);
            window.location.href = '/local/grupomakro_core/pages/classmanagement.php';
        }).fail(function(error) {
            window.console.error(error);
        });
    });

};