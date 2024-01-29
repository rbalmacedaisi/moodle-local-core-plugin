import * as Ajax from 'core/ajax';
import $ from 'jquery';

let selectedInstance;

const typeSelector = $('#classtype');
const classroomSelector = $('#classroom');
const classNameInput = $('#classname');
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
const selectors = [classNameInput,typeSelector, classroomSelector, careerSelector, periodSelector, courseSelector, initTimeInput, endTimeInput];
const switches = [mondaySwitch, tuesdaySwitch, wednesdaySwitch, thursdaySwitch, fridaySwitch, saturdaySwitch, sundaySwitch];

let periods;
let courses;
let teachers;
let classRooms;

const instanceIDs = {
    isi_panama: 0,
    grupomakro_col: 1,
    grupomakro_mex: 2
};

export const init = (classrooms) => {
    classRooms = JSON.parse(classrooms);
    handleInstanceSelection();
    handleCareerSelection();
    handlePeriodSelection();
    handleClassSave();
    handleTypeSelector();
};

const handleTypeSelector = () => {
    typeSelector.change(()=>{
        let classroomSelectorContainer = $("#classroom-fieldset");
        if (typeSelector.val() === '0' || typeSelector.val() === '2') {
            classroomSelector.prop('required', true);
            classroomSelectorContainer.removeClass("d-none");
        } else if (typeSelector.val() === '1') {
            classroomSelector.val("")
            classroomSelector.removeAttr('required');
            classroomSelectorContainer.addClass("d-none");
        }
    });
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
        const instructorId = document.getElementById('instructorId')
        if(instructorId.value == ''){
            return;
        }
        const args = {
            name: classNameInput.val(),
            type: typeSelector.val(),
            instance: instanceIDs[selectedInstance],
            learningPlanId: careerSelector.val(),
            periodId: periodSelector.val(),
            courseId: courseSelector.val(),
            instructorId: instructorId.value,
            initTime: initTimeInput.val(),
            endTime: endTimeInput.val(),
            classDays: formatSelectedClassDays(),
            classroomId: classroomSelector.val(),
            classroomCapacity: classroomSelector.val()
                ? classRooms.find(classroom => classroom.value == classroomSelector.val()).capacity
                : 40
        };
        
        const promise = Ajax.call([{
            methodname: 'local_grupomakro_create_class',
            args
        }]);
        promise[0].done(function(response) {
            if (response.status === -1) {
                // Add the error message to the modal content.
                try {
                    const errorMessages = JSON.parse(response.message);
                    let errorHTMLString = '';
                    errorMessages.forEach(message=>{
                        errorHTMLString += `<p class="text-center">${message}</p>`;
                    });
                    errorModalContent.html(errorHTMLString);
                } catch (error) {
                    errorModalContent.html(`<p class="text-center">${response.message}</p>`);
                } finally {
                    errorModal.modal('show');
                }
                return
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
        }]);
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
        }]);
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


const formatSelectedClassDays = ()=> {
    let daysString = '';
    switches.forEach(day => {
        daysString += `${day.is(":checked") ? 1 : 0}/`;
    });
    return daysString.substring(0, daysString.length - 1);
};

