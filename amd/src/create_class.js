import * as Ajax from 'core/ajax';
import $ from 'jquery';

let selectedInstance;

const typeSelector = $('#class_type');
const classNameInput = $('#classname')
const careerSelector = $('#career');
const periodSelector = $('#period');
const courseSelector = $('#courses');
const teacherSelector = $('#instructor');
const initTimeInput = $('#starttime');
const endTimeInput = $('#endtime');
const saveButton = $('#saveClassButton');
const mondaySwitch = $('#customSwitchMonday');
const tuesdaySwitch = $('#customSwitchTuesday');
const wednesdaySwitch = $('#customSwitchWednesday');
const thursdaySwitch = $('#customSwitchThursday');
const fridaySwitch = $('#customSwitchFriday');
const saturdaySwitch = $('#customSwitchSaturday');
const sundaySwitch = $('#customSwitchSunday');
const errorModal = $('#errorModal');
const errorModalContent = $('#error-modal-content');
const selectors = [ typeSelector, careerSelector, periodSelector, courseSelector, teacherSelector,classNameInput, initTimeInput, endTimeInput];
const switches = [mondaySwitch, tuesdaySwitch, wednesdaySwitch, thursdaySwitch, fridaySwitch, saturdaySwitch, sundaySwitch];

let periods;
let courses;
let teachers;

const instanceIDs = {
    isi_panama: 0,
    grupomakro_col: 1,
    grupomakro_mex: 2
};

export const init = () => {
    handleInstanceSelection();
    handleCareerSelection();
    handlePeriodSelection();
    handleCourseSelection();
    handleClassSave();
};



const handleClassSave = () => {
    saveButton.click(()=>{
        endTimeInput.get(0).setCustomValidity('');
        mondaySwitch.get(0).setCustomValidity('');
        // Check the select inputs and the time inputs
        const valid = selectors.every(selector => {
            return selector.get(0).reportValidity();
        });
        if (!valid) {
            return;
        }
        //
        // Check if the init time is less than the end time of the class
        if (initTimeInput.val() >= endTimeInput.val()) {
            endTimeInput.get(0).setCustomValidity('La hora de finalización debe ser mayor a la hora de inicio.');
            endTimeInput.get(0).reportValidity();
            return;
        }
        //
        // Check if at least one day of the week is selected
        const daySelected = switches.some(day => {
            return day.is(":checked");
        });
        if (!daySelected) {
            mondaySwitch.get(0).setCustomValidity('Se debe seleccionar al menos un día de clase.');
            mondaySwitch.get(0).reportValidity();
            return;
        }
        //
        const args = {
            name: classNameInput.val(),
            type: typeSelector.val(),
            instance: instanceIDs[selectedInstance],
            learningPlanId: careerSelector.val(),
            periodId: periodSelector.val(),
            courseId: courseSelector.val(),
            instructorId: teacherSelector.val(),
            initTime: initTimeInput.val(),
            endTime: endTimeInput.val(),
            classDays: formatSelectedClassDays()
        };
        const promise = Ajax.call([{
            methodname: 'local_grupomakro_create_class',
            args
        }, ]);
        promise[0].done(function(response) {
            window.console.log(response);
            if(response.status === -1 ){
                // Add the error message to the modal content.
                try{
                    const errorMessages = JSON.parse(response.message);
                    let errorHTMLString = '';
                    errorMessages.forEach(message=>{
                        errorHTMLString += `<p class="text-center">${message}</p>`
                    })
                    errorModalContent.html(errorHTMLString);
                }catch (error){
                    errorModalContent.html(`<p class="text-center">${response.message}</p>`);
                } finally{
                    errorModal.modal('show');
                    return
                }
            } 
            window.location.href = '/local/grupomakro_core/pages/classmanagement.php';
        }).fail(function(error) {
            window.console.error(error);
        });

    });
};

const handleInstanceSelection = () => {
    $('a.company-instance').click(function() {
        $('.form-check .card').removeClass('active');
        $(this).parent('.form-check').find('input').click();
        $(this).find('.card').addClass('active');
        selectedInstance = $('input:radio:checked').val();
        $('#fields-groups').removeClass('d-none');
        console.log(selectedInstance)
    });
};

const handleCareerSelection = ()=> {
    careerSelector.change(()=> {
        if (careerSelector.val() === '') {
            return;
        }
        const args = {
            learningPlanId: careerSelector.val()
        };
        const promise = Ajax.call([{
            methodname: 'local_sc_learningplans_get_learning_plan_periods',
            args
        }, ]);
        promise[0].done(function(response) {
            $(".periodValue").remove();
            $(".courseValue").remove();
            $(".teacherValue").remove();

            periods = JSON.parse(response.periods);
            if (!periods.length) {
                periodSelector.val('').change();
                return;
            }
            periods.forEach(({id, name}) => {
                periodSelector.append(`<option class="periodValue" value="${id}">${name}</option>`);
            });
        }).fail(function(response) {
            window.console.error(response);
        });
    });

};

const handlePeriodSelection = () => {
    periodSelector.change(()=> {
        if (periodSelector.val() === '') {
 return;
}
        const args = {
            learningPlanId: careerSelector.val(),
            periodId: periodSelector.val()
        };
        const promise = Ajax.call([{
            methodname: 'local_sc_learningplans_get_learning_plan_courses',
            args
        },]);
        promise[0].done(function(response) {
            $(".courseValue").remove();
            $(".teacherValue").remove();
            courses = JSON.parse(response.courses);
            if (!courses.length) {
                courseSelector.val('').change();
                return;
            }
            courses.forEach(({id, name}) => {
                courseSelector.append(`<option class="courseValue" value="${id}">${name}</option>`);
            });
        }).fail(function(response) {
            window.console.error(response);
        });
    });
};

const handleCourseSelection = () => {
    courseSelector.change(()=> {
        if (courseSelector.val() === '') {
 return;
}
        const args = {
            learningPlanId: careerSelector.val(),
            // periodId: periodSelector.val(),
            // courseId: courseSelector.val()
        };
        const promise = Ajax.call([{
            methodname: 'local_sc_learningplans_get_learning_plan_teachers',
            args
        },]);
        promise[0].done(function(response) {
            $(".teacherValue").remove();
            teachers = JSON.parse(response.teachers);
            if (!teachers.length) {
                teacherSelector.val('').change();
                return;
            }
            teacherSelector.prop('disabled', false);
            teachers.forEach(({userid, fullname, email}) => {
                teacherSelector.append(`<option class="teacherValue" value="${userid}">${fullname} (${email})</option>`);
            });
        }).fail(function(response) {
            window.console.error(response);
        });
    });
};

const formatSelectedClassDays = ()=> {
    let daysString = '';
    switches.forEach(day => {
        daysString += `${day.is(":checked") ? 1 : 0}/`;
    });
    return daysString.substring(0, daysString.length - 1);
};
