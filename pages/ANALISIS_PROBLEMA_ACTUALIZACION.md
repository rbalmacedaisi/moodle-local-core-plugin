# 🔍 Análisis del Problema de Actualización de Estudiantes

## 📋 Campos que NO se están actualizando

1. **Estado Académico** (columna P) - Campo `llu.status` en tabla `local_learning_users`
2. **Periodo Académico** (columna N) - Campo `llu.academicperiodid` en tabla `local_learning_users`
3. **Subperiodo** (columna M) - Campo `llu.currentsubperiodid` en tabla `local_learning_users`
4. **Nivel (Periodo)** (columna L) - Campo `llu.currentperiodid` en tabla `local_learning_users`
5. **Estado Estudiante** (columna X) - Campo personalizado `studentstatus` en tabla `user_info_data`

---

## 🗂️ Mapeo de Columnas del Excel

### Archivo de Exportación Masiva (download_all_students.php)

| Columna | Letra | Campo en Excel | Origen en BD | Variable JS |
|---------|-------|----------------|--------------|-------------|
| 0 | A | Username | `u.username` | `username` |
| 1 | B | Nombres | `u.firstname` | `firstname` |
| 2 | C | Apellidos | `u.lastname` | `lastname` |
| 3 | D | Email Moodle | `u.email` | `email` |
| 4 | E | ID Number | `u.idnumber` | `idnumber` |
| 5 | F | Institución | `u.institution` | `inst` |
| 6 | G | Facultad/Depto | `u.department` | `dept` |
| 7 | H | Teléfono 1 | `u.phone1` | `ph1` |
| 8 | I | Teléfono 2 | `u.phone2` | `ph2` |
| 9 | J | Ciudad | `u.city` | `city` |
| 10 | K | **Plan de Aprendizaje** | `lp.name` | `planName` ✅ |
| 11 | L | **Nivel (Periodo)** | `per.name` | `levelName` ⚠️ |
| 12 | M | **Subperiodo** | `sub.name` | `subName` ⚠️ |
| 13 | N | **Periodo Académico** | `ap.name` | `academicName` ⚠️ |
| 14 | O | Bloque (Grupo) | `llu.groupname` | `groupName` ✅ |
| 15 | P | **Estado Académico** | `llu.status` | `statusField` ⚠️ |
| 16 | Q | Tipo Usuario | Custom field | `uType` ✅ |
| 17 | R | Asesor Comercial | Custom field | `manager` ✅ |
| 18 | S | Fecha Nacimiento | Custom field | `bDate` ✅ |
| 19 | T | Tipo Documento | Custom field | `docType` ✅ |
| 20 | U | Número Documento | Custom field | `docNum` ✅ |
| 21 | V | Paga Matrícula | Custom field | `firstPay` ✅ |
| 22 | W | Correo Personal | Custom field | `personalMail` ✅ |
| 23 | X | **Estado Estudiante** | Custom field | `sStatus` ⚠️ |
| 24 | Y | Género | Custom field | `genre` ✅ |
| 25 | Z | Jornada | Custom field | `journey` ✅ |
| 26 | AA | Móvil Personalizado | Custom field | `cPhone` ✅ |
| 27 | AB | Periodo Ingreso | Custom field | `pIngreso` ✅ |

---

## 🔄 Flujo de Datos: Excel → JavaScript → PHP

### PASO 1: Lectura del Excel (JavaScript líneas 813-892)

```javascript
// Mapeo correcto en isMaster === true
levelName = r[11];        // Columna L ✅
subName = r[12];          // Columna M ✅
academicName = r[13];     // Columna N ✅
statusField = r[15];      // Columna P ✅
sStatus = r[23];          // Columna X ✅
```

### PASO 2: Creación del objeto row (JavaScript líneas 874-892)

```javascript
return {
    username, firstname, lastname,
    fullname: firstname + ' ' + lastname,
    email, idnumber,
    institution: inst, department: dept, phone1: ph1, phone2: ph2, city,
    plan_name: planName, plan_id: plan ? plan.id : null,
    level_name: levelName,           // ✅ Asignado
    subperiod_name: subName,         // ✅ Asignado
    academic_name: academicName,     // ✅ Asignado
    groupname: groupName,
    status: statusField,             // ✅ Asignado

    usertype: uType, accountmanager: manager, birthdate: bDate,
    documenttype: docType, documentnumber: docNum,
    needfirsttuition: firstPay, personalemail: personalMail,
    studentstatus: sStatus,          // ✅ Asignado
    gmkgenre: genre, gmkjourney: journey,
    custom_phone: cPhone, periodo_ingreso: pIngreso,

    status_ui: 'pending'
};
```

### PASO 3: Envío vía AJAX (JavaScript líneas 932-964)

```javascript
const res = await axios.post(url, null, {
    params: {
        action: 'ajax_fix',
        username: row.username,
        // ... otros campos ...
        plan_name: row.plan_name,           // ✅ Se envía
        level_name: row.level_name,         // ✅ Se envía
        subperiod_name: row.subperiod_name, // ✅ Se envía
        academic_name: row.academic_name,   // ✅ Se envía
        groupname: row.groupname,
        status: row.status,                 // ✅ Se envía
        // ... custom fields ...
        studentstatus: row.studentstatus,   // ✅ Se envía
        // ...
    }
});
```

---

## 🔧 Backend PHP: Recepción y Procesamiento (fix_student_setup.php)

### PASO 4: Recepción de parámetros (líneas 148-189)

```php
$level_name = optional_param('level_name', '', PARAM_RAW);         // ✅ Se recibe
$subperiod_name = optional_param('subperiod_name', '', PARAM_RAW); // ✅ Se recibe
$academic_name = optional_param('academic_name', '', PARAM_RAW);   // ✅ Se recibe
$status = optional_param('status', '', PARAM_ALPHA);               // ✅ Se recibe
$studentstatus = optional_param('studentstatus', '', PARAM_ALPHA); // ✅ Se recibe
```

### PASO 5: Resolución de IDs (líneas 214-260)

#### 5.1 Resolución de Nivel/Periodo (líneas 214-230)
```php
$current_period_id = 0;
if (!empty($level_name)) {
    $normalized_lname = php_normalize_field($level_name);
    $plan_periods = $DB->get_records('local_learning_periods',
        ['learningplanid' => $planid], 'id ASC', 'id, name');
    foreach ($plan_periods as $pp) {
        if (php_normalize_field($pp->name) === $normalized_lname) {
            $current_period_id = $pp->id;
            break;
        }
    }
}
// ⚠️ PROBLEMA 1: Si no encuentra match, se asigna ID 0 o el primer periodo (línea 227-229)
if ($current_period_id <= 0) {
    $first_period = $DB->get_record_sql("SELECT id FROM {local_learning_periods}
        WHERE learningplanid = ? ORDER BY id ASC", [$planid], IGNORE_MULTIPLE);
    $current_period_id = $first_period ? $first_period->id : 1;
}
```

❌ **PROBLEMA DETECTADO**: Si el nombre del nivel no hace match exacto, se asigna automáticamente el primer periodo del plan, **ignorando el valor que el usuario quería**.

#### 5.2 Resolución de Subperiodo (líneas 232-243)
```php
$current_subperiod_id = 0;
if (!empty($subperiod_name)) {
    $normalized_sname = php_normalize_field($subperiod_name);
    $subperiods = $DB->get_records('local_learning_subperiods',
        ['learningplanid' => $planid, 'periodid' => $current_period_id],
        'id ASC', 'id, name');
    foreach ($subperiods as $sp) {
        if (php_normalize_field($sp->name) === $normalized_sname) {
            $current_subperiod_id = $sp->id;
            break;
        }
    }
}
// ⚠️ Si no encuentra match, queda en 0 (NO hay fallback)
```

❌ **PROBLEMA DETECTADO**: Depende de que `$current_period_id` sea correcto. Si el periodo se resolvió mal, NUNCA encontrará el subperiodo correcto.

#### 5.3 Resolución de Periodo Académico (líneas 245-260)
```php
$academic_period_id = 0;
if (!empty($academic_name)) {
    $normalized_aname = php_normalize_field($academic_name);
    $all_aps = $DB->get_records('gmk_academic_periods', null, '', 'id, name');
    foreach ($all_aps as $ap) {
        if (php_normalize_field($ap->name) === $normalized_aname) {
            $academic_period_id = $ap->id;
            break;
        }
    }
}
// ⚠️ PROBLEMA 2: Si no encuentra match, asigna el periodo activo (status=1)
if ($academic_period_id <= 0) {
    $academic_period = $DB->get_record('gmk_academic_periods',
        ['status' => 1], 'id', IGNORE_MULTIPLE);
    $academic_period_id = $academic_period ? $academic_period->id : 0;
}
```

❌ **PROBLEMA DETECTADO**: Si el nombre no hace match exacto, se asigna automáticamente el periodo académico activo, **ignorando el valor que el usuario quería**.

### PASO 6: Actualización en BD (líneas 331-342)

```php
if (!$llu) {
    // Creación de nuevo registro
} else {
    $llu->learningplanid = $planid;
    $llu->currentperiodid = $current_period_id;         // ✅ Se actualiza
    if ($current_subperiod_id > 0)
        $llu->currentsubperiodid = $current_subperiod_id; // ⚠️ PROBLEMA 3: Solo si > 0
    $llu->academicperiodid = $academic_period_id;       // ✅ Se actualiza
    if (!empty($groupname))
        $llu->groupname = trim($groupname);             // ⚠️ PROBLEMA 4: Solo si no vacío
    $llu->userrolename = 'student';
    $llu->status = $status;                             // ✅ Se actualiza
    $llu->timemodified = time();
    $llu->usermodified = $USER->id;
    $DB->update_record('local_learning_users', $llu);
}
```

❌ **PROBLEMA 3**: El campo `currentsubperiodid` solo se actualiza si el ID resuelto es > 0. Si el usuario quiere **limpiar** el subperiodo, no se puede.

❌ **PROBLEMA 4**: El campo `groupname` solo se actualiza si no está vacío. Si el usuario quiere **limpiar** el grupo, no se puede.

---

## 🚨 Problemas Identificados

### PROBLEMA CRÍTICO 1: Normalización No Coincide
La función `php_normalize_field()` puede estar normalizando de forma diferente a como están guardados los nombres en la BD, causando que NUNCA haga match y siempre use los valores por defecto.

**Ejemplo:**
- Excel: "Periodo 2024-I"
- BD: "Periodo 2024-I"
- Normalizado Excel: "periodo 2024 i"
- Normalizado BD: "periodo 2024 i"
- **Match: ✅** (debería funcionar)

Pero si en la BD está como "Período 2024-I" (con acento):
- Normalizado BD: "periodo 2024 i"
- **Match: ✅** (debería funcionar por normalización)

### PROBLEMA CRÍTICO 2: Fallbacks Automáticos Silenciosos
Cuando no encuentra match, el sistema asigna valores por defecto **sin avisar al usuario**:
- Nivel → Primer periodo del plan
- Periodo Académico → Periodo activo (status=1)
- Subperiodo → 0 (vacío)

El usuario cree que actualizó, pero en realidad se asignaron valores diferentes.

### PROBLEMA CRÍTICO 3: Imposibilidad de Limpiar Campos
Las condiciones `if ($current_subperiod_id > 0)` y `if (!empty($groupname))` impiden limpiar estos campos.

### PROBLEMA CRÍTICO 4: Estado Estudiante (Custom Field)
El custom field `studentstatus` se recibe correctamente (línea 184) y se pasa a `profile_save_custom_fields()` (línea 292), pero necesitamos verificar que esta función esté funcionando correctamente.

---

## 📊 Plan de Acción

### ACCIÓN 1: Usar la página de debug
1. Accede a: `https://lms.isi.edu.pa/local/grupomakro_core/pages/debug_student_update.php`
2. En el Tab "🔬 Test Resolución", ingresa los valores EXACTOS que tienes en el Excel:
   - Plan de Aprendizaje: (ejemplo: "Licenciatura en Sistemas")
   - Nivel (Periodo): (ejemplo: "Primer Semestre")
   - Subperiodo: (ejemplo: "Bloque 1")
   - Periodo Académico: (ejemplo: "2024-I")
   - Estado Académico: (ejemplo: "activo")
   - Estado Estudiante: (ejemplo: "regular")
3. Haz clic en "🧪 Ejecutar Test de Resolución"
4. **Envíame una captura de pantalla o copia el JSON completo que aparece**

### ACCIÓN 2: Debug de un estudiante específico
1. En el Tab "🎯 Debug Individual", ingresa el username de un estudiante que intentaste actualizar
2. Haz clic en "Analizar"
3. **Envíame el JSON completo que aparece**

### ACCIÓN 3: Verificar nombres en BD
1. En el Tab "👥 Ver Estudiantes", haz clic en "📊 Cargar Estudiantes"
2. Verifica que los nombres de planes, niveles, subperiodos y periodos académicos en la tabla coincidan EXACTAMENTE con lo que tienes en el Excel
3. **Nota cualquier diferencia** (espacios, tildes, mayúsculas, guiones, etc.)

---

## 🔧 Correcciones Propuestas (Pendientes de tu feedback)

Una vez que me envíes los datos del debug, podré hacer las siguientes correcciones:

1. **Mejorar la normalización** para que sea más flexible
2. **Agregar logs de error** cuando no encuentre match (en lugar de usar fallbacks silenciosos)
3. **Permitir limpiar campos** removiendo las condiciones `if ($current_subperiod_id > 0)` y `if (!empty($groupname))`
4. **Verificar el guardado de custom fields** especialmente `studentstatus`
5. **Agregar validación en JavaScript** para avisar al usuario ANTES de enviar si algún nombre no va a hacer match

---

## 📝 Notas Importantes

- El **Estado Académico** (`status`) SÍ se está actualizando correctamente en la línea 338
- El problema principal parece ser la **resolución de nombres a IDs**
- Necesito ver los datos reales de tu BD para entender exactamente qué está fallando
