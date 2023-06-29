import * as Ajax from 'core/ajax';
import $ from 'jquery';

const classNameInput = $('#classname');
const typeSelector = $('#class_type');
const careerSelector = $('#career');
const periodSelector = $('#period');
const courseSelector = $('#courses');
const teacherSelector = $('#instructor');
const initTimeInput = $('#starttime');
const endTimeInput = $('#endtime');
const newDateInput = $('#newDate');
const newStartTimeInput = $('#newStartTime');
const newEndTimeInput = $('#newEndTime');
const saveButton = $('#saveClassButton');
const rescheduleButton = $('#rescheduleActivityButton');
const confirmRescheduleButton = $('#confirmRescheduleActivity');
const cancelRescheduleButton = $('#cancelRescheduleActivity');
const mondaySwitch = $('#customSwitchMonday');
const tuesdaySwitch = $('#customSwitchTuesday');
const wednesdaySwitch = $('#customSwitchWednesday');
const thursdaySwitch = $('#customSwitchThursday');
const fridaySwitch = $('#customSwitchFriday');
const saturdaySwitch = $('#customSwitchSaturday');
const sundaySwitch = $('#customSwitchSunday');
const rescheduleConfirmationText = $('#confirmationTextHolder');
const rescheduleModal = $('#rescheduleActivityModalCenter');
const errorModal = $('#errorModal');
const errorModalContent = $('#error-modal-content');

const selectors = [classNameInput,typeSelector, careerSelector, periodSelector, courseSelector, teacherSelector, initTimeInput, endTimeInput];
const switches = [mondaySwitch, tuesdaySwitch, wednesdaySwitch, thursdaySwitch, fridaySwitch, saturdaySwitch, sundaySwitch];
const classId = window.location.search.substring(10,);
let rescheduling = undefined;
let periods;
let courses;
let teachers;

export const init = (reschedulingActivity) => {
    rescheduling= reschedulingActivity;
    handleCareerSelection();
    handlePeriodSelection();
    handleCourseSelection();
    handleClassSave();
    handleActivityReschedule();
    handleActivityRescheduleConfirmation();
    handleActivityRescheduleCancelation();
};

const handleActivityRescheduleCancelation = () => {
    cancelRescheduleButton.click(()=>{
        rescheduleConfirmationText.html(`<p>Verificando disponibilidad...</p>`);
        confirmRescheduleButton.show()
    })
}


const handleActivityRescheduleConfirmation = () => {
    confirmRescheduleButton.click(()=>{
        const searchParams = new URLSearchParams(window.location.search);
        
        const args = {
            classId:searchParams.get('class_id'),
            moduleId:searchParams.get('moduleId'),
            date:newDateInput.val(),
            initTime:newStartTimeInput.val(),
            endTime:newEndTimeInput.val(),
            sessionId:searchParams.get('sessionId')!==''?searchParams.get('sessionId'):null,
        };
        rescheduleConfirmationText.html(`<p>Reprogramando actividad...</p>`);
        confirmRescheduleButton.prop('disabled',true)
        cancelRescheduleButton.prop('disabled',true)
        const promise = Ajax.call([{
            methodname: 'local_grupomakro_reschedule_activity',
            args
        }, ]);
        promise[0].done(function(response) {
            window.console.log(response);
            if(response.status === -1 ){
                rescheduleConfirmationText.html(`<p>${response.message}</p>`);
                confirmRescheduleButton.prop('disabled',false)
                cancelRescheduleButton.prop('disabled',false)
                return
            }
            rescheduleConfirmationText.html(`<p>Todo listo, Redirigiendo al calendario...</p>`);
            window.location.href ='/local/grupomakro_core/pages/schedules.php';
        }).fail(function(error) {
            window.console.error(error);
        });
    })
}

const handleActivityReschedule = () => {
    rescheduleButton.click((e)=>{
        // Check if the init time is less than the end time of the class
        if (newStartTimeInput.val() >= newEndTimeInput.val()) {
            newEndTimeInput.get(0).setCustomValidity('La hora de finalización debe ser mayor a la hora de inicio.');
            newEndTimeInput.get(0).reportValidity();
            return false
        }
        //
    })
    rescheduleModal.on('show.bs.modal', function (e) {
        const searchParams = new URLSearchParams(window.location.search);
        
        const args = {
            classId:searchParams.get('class_id'),
            moduleId:searchParams.get('moduleId'),
            date:newDateInput.val(),
            initTime:newStartTimeInput.val(),
            endTime:newEndTimeInput.val(),
            sessionId:searchParams.get('sessionId')!==''?searchParams.get('sessionId'):null
        };
        confirmRescheduleButton.hide();
        const promise = Ajax.call([{
            methodname: 'local_grupomakro_check_reschedule_conflicts',
            args
        }, ]);
        promise[0].done(function(response) {
            window.console.log(response);
            rescheduleConfirmationText.html(`<p>${response.message}</p>`);
            if(response.status === -1 ){
                return
            } 
            confirmRescheduleButton.show();
        }).fail(function(error) {
            window.console.error(error);
        });
        return true
    });
}

const handleClassSave = () => {
    saveButton.click(()=>{
        
        endTimeInput.get(0).setCustomValidity('');
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
            classId,
            name: classNameInput.val(),
            type: typeSelector.val(),
            learningPlanId: careerSelector.val(),
            periodId: periodSelector.val(),
            courseId: courseSelector.val(),
            instructorId: teacherSelector.val(),
            initTime: initTimeInput.val(),
            endTime: endTimeInput.val(),
            classDays: formatSelectedClassDays(),
        };
        console.log(args)
        const promise = Ajax.call([{
            methodname: 'local_grupomakro_update_class',
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