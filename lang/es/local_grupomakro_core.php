<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Solutto LMS Core is a plugin used by the various components developed by Solutto.
 *
 * @package    local_soluttolms_core
 * @copyright  2022 Solutto Consulting <dev@soluttoconsulting.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'Grupo Makro Core';
$string['plugin'] = 'Grupo Makro';
$string['emailtemplates_settingspage'] = 'Plantillas de correo electrónico';
$string['class_management'] = 'Administrar clases';
$string['class_schedules'] = 'Horarios de clases';
$string['availability_panel'] = 'Panel de disponibilidad';
$string['availability_calendar'] = 'Calendario de disponibilidad';
$string['institution_management'] = 'Administrar instituciones';

// Capabilities.
$string['grupomakro_core:seeallorders'] = 'Ver todas las órdenes';

// Plantilla de correo electrónico enviada cuando se registra un nuevo estudiante.
$string['emailtemplates_welcomemessage_student'] = 'Mensaje de bienvenida al nuevo estudiante';
$string['emailtemplates_welcomemessage_student_desc'] = 'Esta es la plantilla utilizada para enviar el mensaje de bienvenida a los nuevos estudiantes. Puede usar los siguientes marcadores de posición: {firstname}, {lastname}, {username}, {email}, {sitename}, {siteurl}, {password}';
$string['emailtemplates_welcomemessage_student_default'] = '¡Bienvenido {nombre} {apellido}!';

// Plantilla de correo electrónico enviada cuando se registra un nuevo cuidador.
$string['emailtemplates_welcomemessage_caregiver'] = 'Mensaje de bienvenida al nuevo estudiante';
$string['emailtemplates_welcomemessage_caregiver_desc'] = 'Esta es la plantilla utilizada para enviar el mensaje de bienvenida a los nuevos cuidadores. Puede usar los siguientes marcadores de posición: {firstname}, {lastname}, {username}, {email}, {sitename}, {siteurl}, {password}';
$string['emailtemplates_welcomemessage_caregiver_default'] = '¡Bienvenido {nombre} {apellido}!';

// Asunto del correo electrónico enviado cuando se registra un nuevo usuario.
$string['emailtemplates_welcomemessage_subject'] = 'Bienvenido a Grupo Makro';

// Página de configuración: Configuración financiera.
$string['financial_settingspage'] = 'Detalles financieros';
$string['tuitionfee'] = 'Tarifa de matrícula';
$string['tuitionfee_desc'] = 'Este es el precio de la matrícula definido para todos los usuarios del sitio.';
$string['tuitionfee_discount'] = 'Descuento en la tarifa de matrícula';
$string['tuitionfee_discount_desc'] = 'Este es el % de descuento aplicado a la tasa de matrícula definida para todos los usuarios del sitio.';
$string['currency'] = 'Moneda';
$string['currency_desc'] = 'Esta es la moneda utilizada en toda la plataforma.';
$string['USD'] = 'USD - Dólar estadounidense';
$string['EUR'] = 'EUR - Euro';
$string['COP'] = 'COP - Peso colombiano';
$string['MXN'] = 'MXN - Peso mexicano';
$string['PEN'] = 'PEN - Nuevo sol peruano';
$string['decsep'] = 'Separador decimal';
$string['thousandssep'] = 'Separador de miles';
$string['decsep_dot'] = 'Punto (.)';
$string['decsep_comma'] = 'Coma (,)';
$string['thousandssep_comma'] = 'Coma (,)';
$string['thousandssep_dot'] = 'Punto (.)';
$string['thousandssep_space'] = 'Espacio ( )';
$string['thousandssep_none'] = 'Ninguno';

// Página de órdenes de compra.
$string['orders'] = 'Órdenes';
$string['oid'] = 'ID de pedido';
$string['fullname'] = 'Nombre completo';
$string['itemtype'] = 'Tipo de elemento';
$string['itemname'] = 'Nombre del elemento';
$string['order_date'] = 'Fecha de la pedido';
$string['order_dateupdated'] = 'Fecha de actualización';
$string['order_dateupdated'] = 'Updated Date';
$string['order_status'] = 'Estado del pedido';
$string['order_total'] = 'Total del pedido';

// Página de Gestión de contratos.
$string['contract_management'] = 'Gestión de contratos';
$string['cid'] = 'Contrato';
$string['careers'] = 'Carrera';
$string['user'] = 'Usuario';
$string['state'] = 'Estado';
$string['payment_link'] = 'Link de pago';
$string['options'] = 'Opciones';
$string['create_contract'] = 'Crear contrato';
$string['generate'] = 'Generar';
$string['visualize'] = 'Visualizar';
$string['modify'] = 'Modificar';
$string['remove'] = 'Eliminar';
$string['download'] = 'Descargar';
$string['adviser'] = 'Asesor';
$string['msgconfirm'] = '¿Está seguro de eliminar de forma definitiva este contrato y toda la información relacionada?';
$string['titleconfirm'] = 'Confirmar eliminación';
$string['search'] = 'Buscar';
$string['payment_link_message'] = 'Se ha generado un link de pago para la orden, el usuario asociado al contrato será notificado.';
$string['approve_message'] = 'El contrato ha sido aprobado, se le notificará al usuario que tiene un contrato listo para firmar.';
$string['fixes_message'] = 'El usuario asociado al contrato será notificado sobre las correcciones a realizar.';

// Página Creación de contrato.
$string['title_add_users'] = 'Gestionar usuarios';
$string['select_user'] = 'Selecciona un usuario';
$string['periodicityPayments'] = 'Periodicidad de los pagos';
$string['general_terms'] = 'Términos generales';
$string['manage_careers'] = 'Gestionar Carreras';
$string['select_careers'] = 'Seleccionar una carrera';
$string['payment_type'] = 'Tipo de Pago';
$string['select_payment_type'] = 'Seleccionar Tipo de pago';
$string['cancel'] = 'Cancelar';
$string['continue'] = 'Continuar';
$string['next'] = 'Siguiente';
$string['save'] = 'Guardar';
$string['back'] = 'Atrás';
$string['credit_terms'] = 'Términos del crédito';
$string['select_periodicity'] = 'Selecciona la periodicidad';
$string['number_quotas'] = 'Número Cuotas';
$string['payment_date'] = 'Fecha de pago';
$string['need_co-signer'] = '¿Necesita codeudor?';
$string['cosigner_information'] = 'Información del Codeudor';
$string['name_co_signer'] = 'Nombre del codeudor';
$string['identification_number'] = 'Número de identificación';
$string['phone'] = 'Teléfono';
$string['workplace'] = 'Lugar de trabajo';
$string['msgcreatecontract'] = 'Se ha creado un nuevo contrato, el usuario asociado será notificado y podrá ver el nuevo contrato en su panel.';
$string['upload_documents'] = 'Subir Documentos';
$string['identification_document'] = 'Documento de identificación';
$string['photo_profile_picture'] = 'Foto (imagen de perfil)';
$string["bachelor's_diploma"] = 'Diploma de bachiller o perito';
$string["personal_reference_letter"] = 'Carta de referencia personal';
$string["medical_certificate"] = 'Certificado médico';
$string["diving_certificate"] = 'Certificado de buceo';
$string["work_letter"] = 'Carta de trabajo';
$string["select_date"] = 'Selecciona una fecha';
$string["scheduled_installments"] = 'Cuotas programadas';
$string['step'] = 'Paso';

// General settings page.
$string['general_settingspage'] = 'Configuración general';
$string['inactiveafter_x_hours'] = 'Inactivo después de "X" número de horas';
$cadena['inactivodespués_x_horas_desc'] = '
<p>Esta es la cantidad de horas después de las cuales un usuario se considera inactivo.</p>
<p>Por ejemplo, si establece este valor en 24, un usuario se considerará inactivo si no ha iniciado sesión durante 24 horas y ese usuario será eliminado.</p>
<p><strong>NOTA:</strong> Esta configuración se usa para mantener la plataforma limpia de usuarios que no están realmente interesados en tomar ningún curso o carrera en el sistema.</p>';

// Scheduled tasks.
$string['taskinactiveusers'] = 'Eliminar usuarios inactivos';

// Pagina editar contrato.
$string['editcontract'] = 'Editar Contrato';
$string['defer'] = 'Aplazar';
$string['re_asign'] = 'Reasignar';
$string['user'] = 'Usuario';
$string['msndeferring'] = 'Al aplazar el contrato, todos los pagos relacionados quedarán congelados y el contrato inactivo.';
$string['accept'] = 'Aceptar';
$string['list_advisers'] = 'Lista de asesores';
$string['select_advisor'] = 'Selecciona un asesor';
$string['reassign_contract'] = 'Reasignar Contrato';
$string['cancel_contract'] = 'Cancelar Contrato';
$string['msncancel'] = '¿Está seguro que desea cancelar el contrato? <br>Esta acción no puede deshacerse, tanto la información financiera relacionada al contrato como el estado del estudiante cambiarán a inactivos.';
$string['documents'] = 'Documentos';
$string['approve'] = 'Aprobar';
$string['correct'] = 'Corregir';
$string['fixes'] = 'Correcciones';

// Pagina Gestión de Instituciones.
$string['institutionmanagement'] = 'Gestión de Instituciones';
$string['edit'] = 'Editar';
$string['see'] = 'Ver';
$string['create_institution'] = 'Crear Institución';
$string['update_institution'] = 'Actualizar Institución';
$string['delete_institution_title'] = 'Confirmación eliminación de institución';
$string['delete_institution_message'] = '¿Está seguro de eliminar de forma definitiva esta institución y toda la información relacionada?';

$string['name_institution'] = 'Nombre de la institución';

// Pagina Contratos Institucionales.
$string['institutional_contracts'] = 'Contratos Institucionales';
$string['contractnumber'] = 'Número de contrato';
$string['startdate'] = 'Fecha de inicio';
$string['enddate'] = 'Fecha de finalización';
$string['budget'] = 'Presupuesto';
$string['billing_condition'] = 'Condición de facturación';
$string['users'] = 'Usuarios';
$string['contracts'] = 'Contratos';
$string['totalusers'] = 'Total Usuarios';
$string['name'] = 'Nombre';
$string['email'] = 'Correo electrónico';
$string['phone'] = 'Teléfono';
$string['courses'] = 'Cursos';
$string['user'] = 'Usuario';
$string['adduser'] = 'Agregar Usuario';
$string['generateEnrolLink'] = 'Generar enlace de matrícula';
$string['enrolLinkInfo'] = 'Información enlace de matriculación';
$string['enrolLinkExpirationDate'] = 'Fecha de vencimiento';
$string['enrolLinkUrl'] = 'Enlace';
$string['copyEnrolLink'] = 'Copiar URL';
$string['viewActiveEnrolLinks'] = 'Ver enlaces activos';
$string['enrollUser'] = 'Matricular Usuario';
$string['enrolLinkGeneration'] = 'Generación de enlace de matrícula';
$string['userlist'] = 'Lista de Usuarios';
$string['selectusers'] = 'Seleccionar Usuario';
$string['select_courses'] = 'Seleccionar Cursos';
$string['select_course'] = 'Seleccionar Curso';
$string['actions'] = 'Acciones';
$string['userinformation'] = 'Información del usuario';
$string['details'] = 'Ver Detalle';
$string['profile'] = 'Perfil';
$string['memessage'] = 'Mensaje';
$string['selectcontract'] = 'Seleccionar un contrato';
$string['bulkConfirmationMessage'] = '¿Esta seguro de subir el archivo y crear todos los contratos?';
$string['contractBulkConfirmTitle'] = 'Confirmación creación contratos';
$string['bulkContractCreationReportTitle'] = 'Resultados creación de contratos';
$string['bulkUserIndex'] = 'Indice CSV';
$string['bulkUserError'] = 'Error';

// Contract Enrol Page.
$string['contractenrol'] = 'Matriculación contrato';
$string['contractenrollinkexpirated'] = 'Este link ha vencido.';
$string['invalidtoken'] = 'Token invalido.';
$string['enrol'] = 'Matricular';
$string['enrolUserNotFoundModalMessage'] = 'El documento que ingresaste no parece estar registrado, si te equivocaste puedes volver a intentarlo o tambien puedes crear una cuenta si no tienes una.';
$string['enrolCreateAccount'] = 'Crear cuenta';
$string['enrolTryAgain'] = 'Volver a intentar';
$string['enrolUserNotFound'] = 'No se ha encontrado el usuario';
$string['enrolGeneralInformation'] = 'Información general';
$string['enrolContractLabel'] = 'Contrato: ';
$string['enrolCourseLabel'] = 'Nombre del curso: ';
$string['enrolUserDocumentLabel'] = 'Número de documento';
$string['enrolUserFirstName'] = 'Nombre';
$string['enrolUserLastName'] = 'Apellidos';
$string['enrolUserEmail'] = 'Correo electrónico';

// Pagina Gestión de clases.
$string['classmanagement'] = 'Gestión de clases';
$string['createclass'] = 'Crear Clase';
$string['allclasses'] = 'Todas las Clases';
$string['confirm_delete_class'] = 'Confirmación eliminación de clase';
$string['instances'] = 'Instancias';
$string['select_instance'] = 'Seleccionar Instancia';
$string['period'] = 'Periodos';
$string['select_period'] = 'Seleccionar Periodo';
$string['instructor'] = 'Instructor';
$string['select_instructor'] = 'Seleccionar Instructor';
$string['number_classes'] = 'Número de clases';
$string['start_time'] = 'Hora de inicio';
$string['end_time'] = 'Hora de finalización';
$string['class_type'] = 'Tipo de Clase';
$string['select_type_class'] = 'Selecciona el Tipo de Clase';
$string['monday'] = 'Lunes';
$string['tuesday'] = 'Martes';
$string['wednesday'] = 'Miércoles';
$string['thursday'] = 'Jueves';
$string['friday'] = 'Viernes';
$string['saturday'] = 'Sábado';
$string['sunday'] = 'Domingo';
$string['classdays'] = 'Días de clase';
$string['delete_class'] = '¿Está seguro de eliminar de forma definitiva esta clase y toda la información relacionada?';
$string['edit_class'] = 'Editar Clase';
$string['class_name'] = 'Nombre clase';
$string['new_date'] = 'Nueva fecha';
$string['check_availability'] = 'Ver disponibilidad de instructores';
$string['rescheduling_activity'] = 'Reprogramando actividad de ';
$string['reschedule'] = 'Reprogramar';
$string['confirm_reschedule_title'] = 'Confirmacion reprogramación';

// Pagina de Horarios.
$string['schedules'] = 'Horarios';
$string['availability'] = 'Disponibilidad';
$string['availability_panel'] = 'Panel de Disponibilidad';
$string['today'] = 'Hoy';
$string['add'] = 'Agregar';
$string['day'] = 'Día';
$string['week'] = 'Semana';
$string['month'] = 'Mes';
$string['instructors'] = 'Instructores';
$string['scheduledclasses'] = 'Clases Programadas';
$string['close'] = 'Cerrar';
$string['reschedule'] = 'Reprogramar';
$string['desc_rescheduling'] = 'Describa el motivo de la reprogramación';
$string['available_hours'] = 'Horas Disponibles';
$string['available'] = 'Disponible';
$string['delete_available'] = '¿Está seguro que desea eliminar esta disponibilidad?';
$string['delete_available_confirm'] = 'Al dar clic en aceptar, se eliminarán todos los datos relacionados.';
$string['add_availability'] = 'Agregar Disponibilidad.';
$string['days'] = 'Días';
$string['field_required'] = 'Este campo es obligatorio';
$string['add_schedule'] = 'Agregar Horario';
$string['competences'] = 'Competencias';
$string['causes_rescheduling'] = 'Causales de reprogramación';
$string['select_possible_date'] = 'Seleccionar posible fecha';
$string['new_class_time'] = 'Hora de la nueva clase';
$string['activity'] = 'Actividad';
$string['unable_complete_action'] = 'No es posible completar la acción. El rango seleccionado para edición tiene clases programadas.';
$string['create'] = 'Crear';

// Pagina Panel de Horarios.
$string['schedule_panel'] = 'Panel de Horarios';
$string['scheduleapproval'] = 'Aprobación de horarios';
$string['waitingusers'] = 'Usuarios en Espera';
$string['selection_schedules'] = 'Selección de Horarios';
$string['nodata'] = 'No hay datos';
$string['approve_schedules'] = 'Aprobar Horarios';
$string['registered_users'] = 'Usuarios Inscritos';
$string['waitinglist'] = 'Lista de Espera';
$string['approved'] = 'Aprobado';
$string['class_schedule'] = 'Horario de Clase';
$string['quotas_enabled'] = 'Cupos Habilitados';
$string['approve_users'] = 'Aprobar Usuarios';
$string['move_to'] = 'Mover a:';
$string['current_location'] = 'Ubicación actual:';
$string['student'] = 'Estudiante';
$string['message_approved'] = 'La lista de usuarios para esta clase ha sido aprobada.';
$string['maximum_quota_message'] = 'La clase seleccionada supera el cupo máximo permitido.';
$string['want_to_approve'] = '¿Está seguro que desea aprobarla?';
$string['mminimum_quota_message'] = 'La clase seleccionada no cuenta con el número mínimo de estudiantes permitidos.';
$string['write_reason'] = 'Escribe el motivo';
$string['users'] = 'Usuarios';

//Message providers names
$string['messageprovider:send_reschedule_message'] = 'Envio mensaje de reagendamiento';

//Reschedule Message Body.
$string['msg:send_reschedule_message:body'] = '
<h2>El instructor <strong>{$a->instructorFullName}</strong> ha solicitado una reprogramación con los siguientes causales:</h2>
<q>{$a->causeNames}</q>
<h3>Informacipon de la clase:</h3>
<ul>
    <li><strong>Nombre: </strong>{$a->name}</li>
    <li><strong>Horario: </strong>{$a->originalDate} ({$a->originalHour})</li>
    <li><strong>Curso: </strong>{$a->coreCourseName}</li>
    <li><strong>Modalidad: </strong>{$a->typeLabel}</li>
</ul>
<h3>Horario Propuesto:</h3>
<ul>
    <li><strong>Día: </strong>{$a->proposedDate}</li>
    <li><strong>Hora: </strong>{$a->proposedHour}</li>
</ul>
<p>Para reprogramar la sesión haz click <a href="{$a->rescheduleUrl}">aquí.</a></p>';
$string['msg:send_reschedule_message:subject'] ='Solicitud de reprogramación nueva';
$string['msg:send_reschedule_message:contexturlname'] ='Reprogramar sesión';