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
$string['admin_category_label'] = 'Gestión Academica';
$string['emailtemplates_settingspage'] = 'Plantillas de correo electrónico';
$string['class_management'] = 'Administrar clases';
$string['class_schedules'] = 'Horarios de clases';
$string['availability_panel'] = 'Panel de disponibilidad';
$string['academic_calendar'] = 'Calendario académico';
$string['availability_calendar'] = 'Calendario de disponibilidad';
$string['schedules_panel'] = 'Panel de horarios';
$string['institution_management'] = 'Administrar instituciones';
$string['academic_director_panel'] = 'Panel del Director Académico';
$string['admin_teachers_management'] = 'Administrar Docentes';
$string['teachers'] = 'Docentes';
$string['active_students_by_class_page'] = 'Estudiantes activos por clase';
$string['absence_dashboard'] = 'Inasistencias y Deserciones';

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

$string['credit_terms'] = 'Términos del crédito';
$string['select'] = 'Seleccionar';
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
$string['taskcloseexpiredschedules'] = 'Cerrar horarios vencidos';
$string['enable_absence_alerts'] = 'Activar sistema escalonado de inasistencias';
$string['enable_absence_alerts_desc'] = 'Reemplaza el bloqueo global automático (3 inasistencias) por un sistema escalonado: 1 inasistencia = aviso, 2 inasistencias = popup, 3 inasistencias = bloqueo de la clase. El estudiante pasa a inactivo global solo cuando todas sus clases cursando estén bloqueadas.';
$string['enable_absence_blocking'] = 'Activar bloqueo real de clases por inasistencias';
$string['enable_absence_blocking_desc'] = 'Cuando está activo, los estudiantes que alcancen el umbral son bloqueados en la clase específica. Mantener desactivado durante el despliegue a mitad de período; activar al iniciar el siguiente período académico. Mientras esté desactivado, los estudiantes solo verán las alertas visuales (icono naranja, popup, banner) sin perder acceso.';
$string['absence_block_threshold'] = 'Umbral de inasistencias para bloquear';
$string['absence_block_threshold_desc'] = 'Número de inasistencias en una clase a partir del cual se bloquea el acceso. Por defecto 3.';
$string['overdue_grace_days'] = 'Días de gracia para bloqueo por mora';
$string['overdue_grace_days_desc'] = 'Número de días después del vencimiento antes de restringir el acceso al LXP. Las facturas que vencen el mismo día nunca cuentan como mora. Por defecto 3. Debe coincidir con days_overdue en el cron de mora de Odoo.';
$string['absence_alert_planids'] = 'Planes que generan alertas de inasistencia';
$string['absence_alert_planids_desc'] = 'Selecciona los planes de aprendizaje desde los cuales se generarán alertas de inasistencia. Si no se selecciona ninguno, se generan alertas para todos los planes (comportamiento por defecto). Los planes excluidos no producirán alertas visuales, ni notificaciones, ni recálculos por el cron para sus clases.';

// Bulk exempt / clear period exemptions (mid-period deploy mitigation).
$string['bulk_exempt_legacy_title'] = 'Eximir estudiantes con 3+ inasistencias acumuladas';
$string['bulk_exempt_legacy_desc'] = 'Marca como exentos del nuevo sistema de bloqueo a todos los estudiantes que ya tengan 3 o más inasistencias acumuladas. Se recomienda ejecutar este script al desplegar el sistema a mitad de período, para que solo los estudiantes que lleguen al umbral después del despliegue sean bloqueados. La acción es reversible: la lista de exentos se limpia al iniciar el siguiente período.';
$string['bulk_exempt_legacy_confirm'] = '¿Confirmas la exención masiva? Esta acción marca a todos los estudiantes con 3 o más inasistencias acumuladas para que NO sean bloqueados por el nuevo sistema. Los estudiantes seguirán viendo las alertas visuales (1 y 2 inasistencias) normalmente. Se registrará la operación en el log de auditoría.';
$string['bulk_exempt_legacy_no_op'] = 'No se encontraron estudiantes que requieran exención.';
$string['bulk_exempt_legacy_complete'] = 'Se eximieron {$a->users} estudiantes en {$a->classes} clases. Se registró la operación en el log de auditoría.';

$string['clear_period_exemptions_title'] = 'Limpiar exenciones de período';
$string['clear_period_exemptions_desc'] = 'Elimina todas las exenciones del nuevo sistema de bloqueo por inasistencias. Ejecutar al inicio del siguiente período académico para que las reglas apliquen desde cero.';
$string['clear_period_exemptions_confirm'] = '¿Confirmas la limpieza de exenciones? Esta acción eliminará TODAS las exenciones del nuevo sistema. Los estudiantes que tengan 3+ inasistencias al ejecutar el cron serán bloqueados nuevamente.';
$string['clear_period_exemptions_complete'] = 'Se eliminaron {$a} exenciones.';
$string['taskprocessperiodtransition'] = 'Procesar transición de periodo académico';
$string['taskupdatefinancialstatus'] = 'Actualizar estado financiero de estudiantes (Odoo)';
$string['error_class_closed_modification'] = 'Esta clase está cerrada y no se puede modificar.';

// Pagina editar contrato.
$string['editcontract'] = 'Editar Contrato';
$string['defer'] = 'Aplazar';
$string['re_asign'] = 'Reasignar';
$string['user'] = 'Usuario';
$string['msndeferring'] = 'Al aplazar el contrato, todos los pagos relacionados quedarán congelados y el contrato inactivo.';
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

// Letter requests.
$string['grupomakro_core:manageletters'] = 'Gestionar catálogo de cartas';
$string['grupomakro_core:managerequests'] = 'Gestionar solicitudes de cartas';
$string['grupomakro_core:viewallletterrequests'] = 'Ver todas las solicitudes de cartas';
$string['grupomakro_core:viewabsencedashboard'] = 'Ver panel de inasistencias';

// Absence alert system (staged per-class notifications).
$string['absence_info_subject'] = '{$a}: primera inasistencia registrada';
$string['absence_info_body'] = 'Hemos registrado tu primera inasistencia en la asignatura «{$a->coursename}». A la tercera inasistencia podrías perder el acceso a los recursos y al seguimiento de la misma. Si tienes una justificación, acércate al departamento académico para presentarla.';
$string['absence_warning_subject'] = '{$a}: segunda inasistencia registrada';
$string['absence_warning_body'] = 'La asignatura «{$a->coursename}» cuenta con 2 inasistencias. A la tercera inasistencia perderás el acceso a los recursos y seguimiento de la misma. Si tienes justificación de las inasistencias, acércate al departamento académico para presentar tu justificación.';
$string['absence_block_subject'] = '{$a}: acceso restringido por inasistencias';
$string['absence_block_body'] = 'Tu acceso a la asignatura «{$a->coursename}» se encuentra restringido debido a que alcanzaste el límite de 3 inasistencias. Debes acercarte al área académica para revisar tu caso en caso de tener justificación a las faltas.';

$string['course_blocked_title'] = 'Acceso restringido a la asignatura';
$string['course_blocked_intro'] = 'Tu acceso a la asignatura <strong>{$a}</strong> se encuentra restringido.';
$string['course_blocked_reason'] = 'Motivo: alcanzaste el límite de {$a} inasistencias en esta asignatura.';
$string['course_blocked_action'] = 'Si cuentas con una justificación, acércate al área académica para revisar tu caso. Una vez revisado y aprobado, se restablecerá el acceso a la asignatura.';
$string['course_blocked_back'] = 'Volver al inicio';

// Admin reactivation controls in the absence dashboard.
$string['absence_unblock_class'] = 'Reactivar clase';
$string['absence_unblock_class_confirm'] = '¿Confirmas la reactivación de esta clase para el estudiante? Esta acción levanta el bloqueo por inasistencias, lo registra en el log de auditoría y, si corresponde, reactiva al estudiante a nivel global.';
$string['absence_unblock_class_reason'] = 'Motivo de la reactivación';
$string['absence_unblock_class_success'] = 'La clase fue reactivada correctamente.';
$string['absence_unblock_class_noop'] = 'La clase no se encuentra bloqueada, no se realizó ningún cambio.';
$string['absence_recompute_class'] = 'Recalcular inasistencias';
$string['absence_recompute_class_success'] = 'Estado recalculado: {$a->count} inasistencias (nivel {$a->level}).';

$string['messageprovider:payment_link'] = 'Notificación de enlace de pago para cartas';
$string['messageprovider:letter_generated'] = 'Notificación de carta generada';
$string['messageprovider:ready_for_pickup'] = 'Notificación de carta lista para retirar';
$string['messageprovider:status_changed'] = 'Notificación de cambio de estado de solicitud';

$string['letters_catalog_title'] = 'Catálogo de cartas';
$string['letters_catalog_list'] = 'Cartas registradas';
$string['letters_requests_title'] = 'Bandeja de solicitudes de cartas';
$string['letters_manage_request'] = 'Gestionar solicitud';
$string['letters_filter_status'] = 'Filtrar por estado';
$string['letters_filter'] = 'Filtrar';
$string['letters_all'] = 'Todos';
$string['letters_actions'] = 'Acciones';
$string['letters_col_id'] = 'ID';
$string['letters_col_created'] = 'Fecha de creación';
$string['letters_manage'] = 'Gestionar';
$string['letters_generate_doc'] = 'Generar documento';
$string['letters_doc_generated'] = 'Documento generado correctamente';
$string['letters_status_updated'] = 'Estado actualizado';
$string['letters_new_status'] = 'Nuevo estado';
$string['letters_status_note'] = 'Observación de gestión';
$string['letters_update_status'] = 'Actualizar estado';
$string['letters_save'] = 'Guardar carta';
$string['letters_saved'] = 'Carta guardada correctamente';
$string['letters_deleted'] = 'Carta eliminada correctamente';
$string['letters_delete_request'] = 'Eliminar solicitud';
$string['letters_delete_request_confirm'] = 'Desea eliminar definitivamente esta solicitud de carta? Esta accion no se puede deshacer.';
$string['letters_request_deleted'] = 'Solicitud eliminada definitivamente';
$string['letters_cancel_edit'] = 'Cancelar edición';

$string['letters_field_code'] = 'Código';
$string['letters_field_name'] = 'Nombre de carta';
$string['letters_field_warning'] = 'Advertencia para estudiante';
$string['letters_field_cost'] = 'Costo';
$string['letters_field_active'] = 'Activa';
$string['letters_field_deliverymode'] = 'Modo de entrega';
$string['letters_field_generationmode'] = 'Modo de generación';
$string['letters_field_autostamp'] = 'Aplicar sello automático';
$string['letters_field_autosignature'] = 'Aplicar firma automática';
$string['letters_field_stampimageurl'] = 'URL/Path imagen de sello';
$string['letters_field_signatureimageurl'] = 'URL/Path imagen de firma';
$string['letters_field_odoo_product'] = 'ID producto Odoo';
$string['letters_field_template'] = 'Plantilla HTML';
$string['letters_field_datasets'] = 'Datasets habilitados';
$string['letters_delivery_digital'] = 'Digital';
$string['letters_delivery_fisica'] = 'Física';
$string['letters_generation_auto'] = 'Automática';
$string['letters_generation_manual'] = 'Manual';

$string['letter_status_solicitada'] = 'Solicitada';
$string['letter_status_pendiente_pago'] = 'Pendiente de pago';
$string['letter_status_pagada'] = 'Pagada';
$string['letter_status_generada_digital'] = 'Generada digitalmente';
$string['letter_status_pendiente_gestion'] = 'Pendiente de gestión académica';
$string['letter_status_pendiente_recoleccion'] = 'Pendiente de recolección';
$string['letter_status_entregada'] = 'Entregada';
$string['letter_status_rechazada'] = 'Rechazada';
$string['letter_status_cancelada'] = 'Cancelada';

$string['letter_type_not_found'] = 'No se encontró el tipo de carta solicitado.';
$string['letter_invalid_transition'] = 'Transición de estado inválida: {$a}.';
$string['letter_document_not_found'] = 'No se encontró el documento de carta generado.';
$string['letter_documentnumber_required'] = 'El estudiante no tiene número de documento para facturación.';
$string['letter_invoice_error'] = 'No se pudo crear la factura de la solicitud de carta: {$a}.';

$string['letter_payment_subject'] = 'Solicitud de carta con pago pendiente: {$a}';
$string['letter_payment_body'] = 'Su solicitud de carta "{$a->lettername}" (ID: {$a->requestid}) requiere pago. Monto: {$a->amount}. Enlace de pago: {$a->paymentlink}';
$string['letter_generated_subject'] = 'Carta generada: {$a}';
$string['letter_generated_body'] = 'Su carta "{$a->lettername}" (ID de solicitud: {$a->requestid}) ya fue generada y está disponible para descarga en la plataforma.';
$string['letter_pickup_subject'] = 'Carta lista para recolección: {$a}';
$string['letter_pickup_body'] = 'Su carta "{$a->lettername}" (ID de solicitud: {$a->requestid}) está lista para retirar en las instalaciones del instituto.';
$string['letter_status_subject'] = 'Actualización de estado de solicitud de carta: {$a}';
$string['letter_status_body'] = 'Su solicitud de carta "{$a->lettername}" (ID: {$a->requestid}) cambió al estado: {$a->status}.';
$string['letter_context_url_name'] = 'Ver detalle de solicitud de carta';
$string['letter_verify_page_title'] = 'Verificacion de carta';
$string['letter_verify_page_heading'] = 'Verificacion publica de carta generada';
$string['letter_verify_intro'] = 'Escanee el codigo QR incluido en la carta o abra el enlace de verificacion para validar su autenticidad.';
$string['letter_verify_missing_token'] = 'No se recibio el token de verificacion.';
$string['letter_verify_invalid'] = 'Token de verificacion invalido. No existe una carta generada con ese codigo.';
$string['letter_verify_valid'] = 'Documento valido: esta carta fue generada por el instituto.';
$string['letter_verify_field_requestid'] = 'ID de solicitud';
$string['letter_verify_field_documentid'] = 'ID de documento';
$string['letter_verify_field_letter'] = 'Tipo de carta';
$string['letter_verify_field_student'] = 'Estudiante';
$string['letter_verify_field_status'] = 'Estado actual';
$string['letter_verify_field_generated'] = 'Fecha de generacion';
$string['letter_verify_field_version'] = 'Version del documento';
$string['selectcontract'] = 'Seleccionar un contrato';
$string['bulkConfirmationMessage'] = '¿Esta seguro de subir el archivo y crear todos los contratos?';
$string['contractBulkConfirmTitle'] = 'Confirmación creación contratos';
$string['bulkContractCreationReportTitle'] = 'Resultados creación de contratos';
$string['bulkUserIndex'] = 'Indice CSV';
$string['bulkUserError'] = 'Error';
$string['apply_filter'] = 'Aplicar filtro';
$string['remove_filter'] = 'Remover filtro';
$string['active_users'] = 'Usuarios activos';
$string['active_students'] = 'Estudiantes activos';
$string['active_courses'] = 'Cursos activos';
$string['my_active_classes'] = 'Mis clases activas';
$string['pending_tasks'] = 'Tareas pendientes';
$string['next_session'] = 'Siguiente sesión';
$string['no_groups'] = 'No hay grupos asignados';

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
$string['notassigned'] = 'No asignado';

// Pagina Gestión de clases.----------------------------------------------------

//Class management pages labels and texts
$string['confirm_delete_class'] = 'Confirmación eliminación de clase';
$string['delete_class'] = '¿Está seguro de eliminar de forma definitiva esta clase y toda la información relacionada?';
$string['deleteselected'] = 'Eliminar seleccionados';
$string['allclasses'] = 'Todas las Clases';
$string['instructor'] = 'Instructor';
$string['class_management'] = 'Gestión de clases';
$string['gridview'] = 'Vista de cuadrícula';
$string['listview'] = 'Vista de lista';
$string['noclasses'] = 'No hay clases disponibles';
$string['create_class'] = 'Crear Clase';
$string['edit_class'] = 'Editar Clase';
$string['class_general_data'] = 'Datos Generales';
$string['class_name'] = 'Nombre de clase';
$string['class_type'] = 'Tipo de clase';
$string['class_room'] = 'Aula de clase';
$string['class_learning_plan'] = 'Plan de aprendizaje';
$string['class_lective_period'] = 'Período lectivo';
$string['class_period'] = 'Período';
$string['class_course'] = 'Curso';
$string['class_date_time'] = 'Horario y días de clase';
$string['class_start_time'] = 'Hora de inicio';
$string['class_end_time'] = 'Hora de finalización';
$string['class_days'] = 'Días de clase';
$string['init_date'] = 'Fecha de Inicio';
$string['end_date'] = 'Fecha de Finalización';
$string['class_available_instructors'] = 'Instructores disponibles para la clase';
$string['see_availability'] = 'Ver disponibilidad';

//Class creation and edition input placeholders

$string['class_name_placeholder'] = 'Ingrese un nombre';
$string['class_type_placeholder'] = 'Seleccionar Tipo de clase';
$string['class_room_placeholder'] = 'Seleccionar Aula de clase';
$string['class_learningplan_placeholder'] = 'Seleccionar Plan de aprendizaje';
$string['class_lective_period_placeholder'] = 'Seleccionar Período lectivo';
$string['class_period_placeholder'] = 'Seleccionar Período';
$string['class_course_placeholder'] = 'Seleccionar Curso';

//Class activity reschedule labels and placeholders
$string['rescheduling_activity'] = 'Reprogramando actividad de ';
$string['activity_new_date'] = 'Nueva fecha';
$string['activity_start_time'] = 'Hora de inicio';
$string['activity_end_time'] = 'Hora de finalización';
$string['reschedule'] = 'Reprogramar';

//Class types labels
$string['classType_0'] = 'Presencial';
$string['classType_1'] = 'Virtual';
$string['classType_2'] = 'Mixta';

//------------------------------------------------------------------------------

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
$string['move_to'] = 'Mover';
$string['current_location'] = 'Ubicación actual:';
$string['student'] = 'Estudiante';
$string['message_approved'] = 'La lista de usuarios para esta clase ha sido aprobada.';
$string['maximum_quota_message'] = 'La clase seleccionada supera el cupo máximo permitido.';
$string['want_to_approve'] = '¿Está seguro que desea aprobarla?';
$string['mminimum_quota_message'] = 'La clase seleccionada no cuenta con el número de estudiantes permitidos.';
$string['write_reason'] = 'Escribe el motivo';
$string['users'] = 'Usuarios';
$string['add_schedules'] = 'Agregar Horarios';
$string['deleteusersclass'] = 'La clase seleccionada tiene estudiantes inscritos. ¿Está seguro que desea eliminarla?';
$string['deleteclassMessage'] = '¿Está seguro que desea eliminar la clase seleccionada?';
$string['waitinglists'] = 'Listas de espera';
$string['selectall'] = 'Seleccionar todo';
$string['deleteusersmessage'] = '¿Está seguro que desea eliminar los usuarios seleccionados?';
$string['class'] = 'Clases';
$string['approval_message_title'] = 'Mensaje de Aprobación';
$string['course'] = 'Curso';
$string['no_users_message'] = 'no cuenta con el número de estudiantes permitidos.';
$string['aproved_message_hinit'] = 'Mensaje para justificar aprobación de horario.';
$string['onhold'] = 'En espera';
$string['noschedule'] = 'Sin horario';
$string['nouserswaitinglist'] = 'No hay Usurios en Lista de espera.';
$string['deletion_message'] = 'Mensaje de Eliminación';

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
    <li><strong>Modalidad: </strong>{$a->typelabel}</li>
</ul>
<h3>Horario Propuesto:</h3>
<ul>
    <li><strong>Día: </strong>{$a->proposedDate}</li>
    <li><strong>Hora: </strong>{$a->proposedHour}</li>
</ul>
<p>Para reprogramar la sesión haz click <a href="{$a->rescheduleUrl}">aquí.</a></p>';
$string['msg:send_reschedule_message:subject'] = 'Solicitud de reprogramación nueva';
$string['msg:send_reschedule_message:contexturlname'] = 'Reprogramar sesión';

//Revalidation Message Body.
$string['msg:send_revalidation_message:body'] = '
<h2>El curso <strong>{$a->courseName}</strong> puede ser revalidado.</h2>
<p>Haz click <a href="{$a->payRevalidUrl}">aquí</a> para pagar la revalida.</p>';
$string['msg:send_revalidation_message:subject'] = 'Nueva revalida pendiente';
$string['msg:send_revalidation_message:contexturlname'] = 'Pagar revalida.';

// Pagina Panel de Disponivilidad.
$string['availability_bulk_load'] = 'Carga masiva de disponibilidad';
$string['start_time'] = 'Hora de inicio';
$string['end_time'] = 'Hora de finalización';

// Pagina del Panel Academico
$string['academic_panel'] = 'Panel académico';
$string['academic_record'] = 'Historial Académico';
$string['prerequisites'] = 'Prerrequisitos';
$string['hours'] = 'Horas';
$string['quarters'] = 'Cuatrimestres';
$string['courses'] = 'Cursos';
$string['see_curriculum'] = 'Ver Malla';
$string['students_per_page'] = 'Estudiantes por Página';
$string['students_list'] = 'Lista de estudiantes';
$string['revalidation'] = 'Revalida';
$string['there_no_data'] = 'No hay datos';
$string['grades'] = 'Calificaciones';
$string['pensum'] = 'Pénsum';

//Academic Calendar Table.
$string['academiccalendar:academic_calendar_title'] = 'Calendario Académico';
$string['academiccalendar:upload_calendar_label'] = 'Cargar calendario';
$string['academiccalendar:calendar_table_title'] = 'Períodos Académicos';
$string['academiccalendar:upload_modal_title'] = '¿Cargar archivo de Excel y actualizar períodos académicos?';
$string['academiccalendar:academic_calendar_breadcrumb:academic_panel'] = 'Panel Académico';
$string['academiccalendar:academic_calendar_breadcrumb:academic_calendar'] = 'Calendario';

$string['academiccalendar:academic_calendar_table:period_header'] = 'Períodos';
$string['academiccalendar:academic_calendar_table:bimesters_header'] = 'Bimestres';
$string['academiccalendar:academic_calendar_table:period_init_header'] = 'Inicio';
$string['academiccalendar:academic_calendar_table:period_end_header'] = 'Fin';
$string['academiccalendar:academic_calendar_table:induction_header'] = 'Inducción';
$string['academiccalendar:academic_calendar_table:final_exam_header'] = 'Examen Final';
$string['academiccalendar:academic_calendar_table:loadnotesandclosesubjects_header'] = 'Notas y cierre de Materias';
$string['academiccalendar:academic_calendar_table:delivoflistforrevalbyteach_header'] = 'Entrega Listados Revalidas Docente';
$string['academiccalendar:academic_calendar_table:notiftostudforrevalidations_header'] = 'Notificación para Revalidas';
$string['academiccalendar:academic_calendar_table:deadlforpayofrevalidations_header'] = 'Pago de Revalidas';
$string['academiccalendar:academic_calendar_table:revalidationprocess_header'] = 'Realización de Revalidas';
$string['academiccalendar:academic_calendar_table:registration_header'] = 'Matriculación';
$string['academiccalendar:academic_calendar_table:graduationdate_header'] = 'Fecha de Graduación';

$string['academiccalendar:academic_calendar_table:final_exam_cell'] = 'Del {$a->examFrom} Al {$a->examUntil}';
$string['academiccalendar:academic_calendar_table:registration_cell'] = 'Del {$a->registrationFrom} Al {$a->registrationUntil}';

//Utils

////Error
$string['nopermissiontoseeschedules'] = "El usuario no tiene el rol requerido para ver los horarios.";

////Days
$string['monday'] = 'Lunes';
$string['tuesday'] = 'Martes';
$string['wednesday'] = 'Miércoles';
$string['thursday'] = 'Jueves';
$string['friday'] = 'Viernes';
$string['saturday'] = 'Sábado';
$string['sunday'] = 'Domingo';

////Button Labels
$string['accept'] = 'Aceptar';
$string['cancel'] = 'Cancelar';
$string['continue'] = 'Continuar';
$string['next'] = 'Siguiente';
$string['save'] = 'Guardar';
$string['back'] = 'Atrás';
$string['close'] = 'Cerrar';
$string['not_add_schedules'] = 'Actualmente no hay clases disponibles. Por favor, cree una nueva clase para asignar a los usuarios que no tienen horarios.';
$string['errorproxy'] = 'Error al conectar con el proxy de Odoo: {$a}';
$string['academic_level'] = 'Nivel Académico';
$string['update_class_period'] = 'Actualizar periodo lectivo';
$string['new_academic_period'] = 'Nuevo periodo lectivo';
$string['update_class_period_warning'] = 'Esto actualizará el periodo lectivo de todos los estudiantes matriculados en la clase. El cuatrimestre y bimestre no se ven afectados.';
$string['enrolled_students'] = 'Estudiantes matriculados';
$string['update'] = 'Actualizar';

////BBB Live Attendance
$string['fix_bbb_live_attendance'] = 'Corregir Asistencia BBB en Vivo';

////Capabilities
$string['grupomakro_core:manage_classes'] = 'Gestionar clases';

////Diplomas
$string['diploma_management'] = 'Gestión de Diplomas';
$string['diploma_templates'] = 'Plantillas de Diplomas';
$string['diploma_templates_title'] = 'Editor de Plantillas de Diploma';
$string['diploma_generation_title'] = 'Generación de Diplomas';
$string['diploma_template_name'] = 'Nombre de la plantilla';
$string['diploma_template_name_desc'] = 'Nombre interno para identificar esta plantilla.';
$string['diploma_template_description'] = 'Descripción';
$string['diploma_template_description_desc'] = 'Descripción opcional para contextualizar la plantilla.';
$string['diploma_template_status'] = 'Estado';
$string['diploma_template_active'] = 'Activa';
$string['diploma_template_inactive'] = 'Inactiva';
$string['diploma_template_orientation'] = 'Orientación';
$string['diploma_orientation_landscape'] = 'Horizontal (A4 297x210 mm)';
$string['diploma_orientation_portrait'] = 'Vertical (A4 210x297 mm)';
$string['diploma_background'] = 'Imagen de fondo';
$string['diploma_background_help'] = 'Sube la imagen que servirá como arte del diploma. Se recomienda alta resolución (mínimo 3508x2480 px para A4 horizontal a 300 DPI).';
$string['diploma_upload_background'] = 'Subir imagen de fondo';
$string['diploma_no_background'] = 'Aún no se ha cargado una imagen de fondo.';
$string['diploma_replace_background'] = 'Reemplazar imagen';
$string['diploma_canvas'] = 'Lienzo de diseño';
$string['diploma_canvas_help'] = 'Haz clic en "Añadir elemento" para insertar textos o el código QR. Arrastra para mover, usa los handles para redimensionar y el handle superior para rotar.';
$string['diploma_fields'] = 'Elementos en la plantilla';
$string['diploma_add_element'] = 'Añadir elemento';
$string['diploma_element_type'] = 'Tipo de elemento';
$string['diploma_element_type_variable'] = 'Variable del estudiante';
$string['diploma_element_type_custom'] = 'Texto personalizado';
$string['diploma_element_type_qr'] = 'Código QR de verificación';
$string['diploma_element_type_static'] = 'Texto estático (sin variable)';
$string['diploma_variable'] = 'Variable';
$string['diploma_custom_text'] = 'Texto';
$string['diploma_static_text'] = 'Texto fijo';
$string['diploma_position'] = 'Posición (mm)';
$string['diploma_position_x'] = 'X';
$string['diploma_position_y'] = 'Y';
$string['diploma_size'] = 'Tamaño';
$string['diploma_size_width'] = 'Ancho';
$string['diploma_size_height'] = 'Alto';
$string['diploma_rotation'] = 'Rotación';
$string['diploma_rotation_deg'] = '°';
$string['diploma_font'] = 'Fuente';
$string['diploma_font_size'] = 'Tamaño de fuente';
$string['diploma_font_weight'] = 'Peso';
$string['diploma_font_weight_normal'] = 'Normal';
$string['diploma_font_weight_bold'] = 'Negrita';
$string['diploma_font_color'] = 'Color';
$string['diploma_text_align'] = 'Alineación';
$string['diploma_align_left'] = 'Izquierda';
$string['diploma_align_center'] = 'Centro';
$string['diploma_align_right'] = 'Derecha';
$string['diploma_line_height'] = 'Altura de línea';
$string['diploma_z_index'] = 'Orden (profundidad)';
$string['diploma_save_template'] = 'Guardar plantilla';
$string['diploma_save_template_success'] = 'Plantilla guardada correctamente.';
$string['diploma_delete_template'] = 'Eliminar plantilla';
$string['diploma_delete_template_confirm'] = '¿Está seguro de eliminar esta plantilla? Los diplomas ya emitidos no se verán afectados.';
$string['diploma_template_deleted'] = 'Plantilla eliminada.';
$string['diploma_no_templates'] = 'Aún no hay plantillas registradas. Cree una nueva para comenzar.';
$string['diploma_select_template'] = 'Seleccione una plantilla';
$string['diploma_duplicate_template'] = 'Duplicar plantilla';
$string['diploma_template_duplicated'] = 'Plantilla duplicada.';
$string['diploma_editor_loading'] = 'Cargando editor...';
$string['diploma_no_fields'] = 'Aún no hay elementos. Añada variables, texto personalizado o un código QR.';

$string['diploma_var_fullname'] = 'Nombre completo';
$string['diploma_var_firstname'] = 'Nombre';
$string['diploma_var_lastname'] = 'Apellido';
$string['diploma_var_idnumber'] = 'Identificación';
$string['diploma_var_documentnumber'] = 'Cédula / Documento';
$string['diploma_var_email'] = 'Correo electrónico';
$string['diploma_var_phone'] = 'Teléfono';
$string['diploma_var_address'] = 'Dirección';
$string['diploma_var_career'] = 'Carrera';
$string['diploma_var_learningplan'] = 'Plan de aprendizaje';
$string['diploma_var_period'] = 'Periodo actual';
$string['diploma_var_subperiod'] = 'Bloque actual';
$string['diploma_var_issue_date'] = 'Fecha de emisión';
$string['diploma_var_diploma_number'] = 'Número de diploma';
$string['diploma_var_diploma_version'] = 'Versión del documento';
$string['diploma_var_institution'] = 'Nombre de la institución';
$string['diploma_var_campus'] = 'Sede / Recinto';

$string['diploma_generation'] = 'Generación de diplomas';
$string['diploma_filter_career'] = 'Filtrar por carrera';
$string['diploma_all_careers'] = 'Todas las carreras';
$string['diploma_eligible_count'] = '{$a} estudiantes elegibles sin diploma generado.';
$string['diploma_selected_count'] = '{$a} seleccionados';
$string['diploma_search_student'] = 'Buscar estudiante (nombre, cédula o identificación)';
$string['diploma_no_graduands'] = 'No hay estudiantes que cumplan los requisitos de grado sin diploma generado para el filtro actual.';
$string['diploma_generate_selected'] = 'Generar diplomas';
$string['diploma_generate_for'] = 'Generar diploma para {$a} estudiante(s)';
$string['diploma_generation_in_progress'] = 'Generando diplomas, por favor espere...';
$string['diploma_generation_done'] = 'Se generaron {$a->success} diploma(s). Errores: {$a->errors}.';
$string['diploma_generation_partial'] = 'Se generaron {$a->success} diploma(s), pero {$a->errors} fallaron.';
$string['diploma_download_all'] = 'Descargar ZIP con todos';
$string['diploma_download_pdf'] = 'Descargar PDF';
$string['diploma_no_template_selected'] = 'Debe seleccionar una plantilla antes de generar.';
$string['diploma_no_students_selected'] = 'Debe seleccionar al menos un estudiante.';
$string['diploma_generated_records'] = 'Diplomas generados';
$string['diploma_generated_at'] = 'Emitido';
$string['diploma_generated_for'] = 'Para';
$string['diploma_template_used'] = 'Plantilla';
$string['diploma_status_generated'] = 'Generado';
$string['diploma_status_revoked'] = 'Revocado';
$string['diploma_view_certificate'] = 'Ver certificado público';
$string['diploma_revoke'] = 'Revocar';
$string['diploma_revoke_confirm'] = '¿Está seguro de revocar este diploma? La URL pública dejará de funcionar.';
$string['diploma_revoked_ok'] = 'Diploma revocado.';
$string['diploma_delete'] = 'Eliminar registro';
$string['diploma_delete_confirm'] = '¿Está seguro de eliminar este registro de diploma? Esta acción NO se puede deshacer y borrará también el archivo PDF generado.';
$string['diploma_deleted_ok'] = 'Registro de diploma eliminado.';
$string['diploma_filter_status'] = 'Estado';
$string['diploma_all_status'] = 'Todos';
$string['diploma_list_only_pending'] = 'Solo pendientes';
$string['diploma_list_generated'] = 'Generados';

$string['diploma_verify_page_title'] = 'Verificación de Diploma';
$string['diploma_verify_page_heading'] = 'Diploma verificado';
$string['diploma_verify_invalid_token'] = 'Token inválido. No se encontró un diploma con este código.';
$string['diploma_verify_revoked'] = 'Este diploma fue revocado y la verificación ya no es válida.';
$string['diploma_verify_student'] = 'Estudiante';
$string['diploma_verify_career'] = 'Carrera';
$string['diploma_verify_plan'] = 'Plan de aprendizaje';
$string['diploma_verify_issued_at'] = 'Fecha de emisión';
$string['diploma_verify_diploma_number'] = 'Número de diploma';
$string['diploma_verify_version'] = 'Versión del documento';
$string['diploma_verify_institution'] = 'Institución';
$string['diploma_verify_status'] = 'Estado';
$string['diploma_verify_status_valid'] = 'Válido';
$string['diploma_verify_status_revoked'] = 'Revocado';
$string['diploma_verify_share'] = 'Compartir este diploma';
$string['diploma_verify_legend'] = 'Este certificado ha sido emitido por la institución y es verificable mediante el código QR.';
$string['diploma_verify_back_to_site'] = 'Volver al sitio institucional';
$string['diploma_verify_loading'] = 'Verificando diploma...';
$string['diploma_certified_to'] = 'Se certifica que';
$string['diploma_certified_career_prefix'] = 'ha culminado satisfactoriamente la carrera de';
$string['diploma_certified_career_suffix'] = '.';

$string['diploma_cap:manage'] = 'Gestionar diplomas y plantillas';
$string['diploma_cap:view'] = 'Ver diplomas generados';
$string['diploma_cap:verify'] = 'Verificar diplomas públicamente';
$string['diploma_no_file'] = 'No se recibió la imagen. Intente nuevamente.';
$string['diploma_too_large'] = 'La imagen excede el tamaño máximo permitido (10 MB).';
