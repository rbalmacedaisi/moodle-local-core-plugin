# Diagnóstico del problema de actualización de estados

## Pasos para diagnosticar el problema:

### 1. Abre la consola del navegador
- Presiona `F12` en tu navegador
- Ve a la pestaña "Consola" (Console)

### 2. Verifica las variables globales
Ejecuta este código en la consola:

```javascript
console.log('=== DIAGNÓSTICO ===');
console.log('Token:', window.userToken);
console.log('isAdmin:', window.isAdmin);
console.log('isSuperAdmin:', window.isSuperAdmin);
console.log('Site URL:', window.location.origin + '/webservice/rest/server.php');
```

**Resultados esperados:**
- `Token`: Debe mostrar un token alfanumérico largo
- `isAdmin`: Debe ser `true`
- `isSuperAdmin`: Puede ser `true` o `false`
- `Site URL`: Debe ser la URL completa de tu Moodle + `/webservice/rest/server.php`

### 3. Verifica que el componente Vue esté cargado
```javascript
console.log('Vue components:', Object.keys(Vue.options.components));
```

**Resultado esperado:** Debe incluir `'studenttable'` en la lista

### 4. Intercepta los errores
Antes de hacer clic en un estado, ejecuta:

```javascript
window.addEventListener('error', function(e) {
    console.error('ERROR CAPTURADO:', e.message, e.error);
});
```

Luego intenta cambiar un estado y observa si aparece algún error.

### 5. Verifica la respuesta del servidor
Abre la pestaña "Red" (Network) en las herramientas de desarrollador (F12):
- Ve a la pestaña "Red" o "Network"
- Intenta cambiar un estado
- Busca la petición a `server.php`
- Haz clic en ella y ve a la pestaña "Response" o "Respuesta"
- Copia la respuesta aquí

### 6. Prueba manual de la función
Ejecuta esto en la consola (reemplaza los valores según tu caso):

```javascript
// Test manual de actualización
const testUpdate = async () => {
    const formData = new FormData();
    formData.append('wstoken', window.userToken);
    formData.append('wsfunction', 'local_grupomakro_update_student_status');
    formData.append('moodlewsrestformat', 'json');
    formData.append('userid', 123); // REEMPLAZA con un ID de usuario real
    formData.append('field', 'academicstatus');
    formData.append('value', 'desertor');

    try {
        const response = await axios.post(
            window.location.origin + '/webservice/rest/server.php',
            formData
        );
        console.log('Respuesta:', response.data);
    } catch (error) {
        console.error('Error:', error);
    }
};

testUpdate();
```

## Problemas comunes y soluciones:

### ❌ Si `window.userToken` es `undefined`:
**Problema:** El token no está siendo inyectado correctamente en la página.
**Solución:** Verifica el archivo PHP que carga la página del panel académico.

### ❌ Si `window.isAdmin` es `false`:
**Problema:** El usuario no tiene permisos de administrador.
**Solución:** Los dropdowns no se mostrarán. Verifica los permisos del usuario.

### ❌ Si la petición devuelve un error 401 o "Invalid token":
**Problema:** El token no es válido o expiró.
**Solución:** Cierra sesión y vuelve a iniciar sesión.

### ❌ Si la petición devuelve "Function not found":
**Problema:** El servicio web no está registrado correctamente.
**Solución:** Ejecuta desde el servidor:
```bash
cd /ruta/a/moodle
php admin/cli/upgrade.php
```

### ❌ Si aparece un error de CORS:
**Problema:** La petición está siendo bloqueada por políticas de seguridad.
**Solución:** Verifica la configuración de Moodle.

### ❌ Si la respuesta es `{"exception":"..."}`:
**Problema:** Hay un error en el backend.
**Solución:** Revisa los logs de Moodle en `/var/log` o similar.

## Siguiente paso:
Una vez que ejecutes estos diagnósticos, comparte los resultados para poder identificar exactamente dónde está el problema.
