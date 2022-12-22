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
$string['emailtemplates_settingspage'] = 'Plantillas de correo electrónico';

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