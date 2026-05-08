# Guía de Diseño para Módulos Moodle Tipo SKILL

## 1. Introducción

Esta guía documenta el lenguaje de diseño utilizado en los módulos Moodle desarrollados para el proyecto ISI. Los módulos siguen un patrón SPA (Single Page Application) utilizando Vue.js 2 y Vuetify 2嵌入ados en páginas PHP de Moodle.

### 1.1 Archivos de Referencia

| Archivo | Propósito |
|---------|-----------|
| `grupomakro_core/pages/academicpanel.php` | Página PHP shell |
| `grupomakro_core/js/components/studenttable.js` | Componente Vue de tabla |

---

## 2. Estructura de Archivos

### 2.1 Estructura de un Módulo

```
local_[modulename]/
├── pages/
│   └── [modulename].php        # Página principal
├── lang/
│   └── es/
│       └── local_[modulename].php  # Strings
├── js/
│   ├── app.js                # Componente raíz Vue
│   └── components/
│       ├── [component1].js  # Componentes hijos
│       └── [component2].js
├── lib.php                   # Functions库
├── locallib.php             # Lógica principal
├── settings.php             # Configuración admin
└── version.php              # Versión
```

### 2.2 Naming Convention

- **Módulos**: `local_[nombre]` (ej: `local_skilltracker`)
- **Páginas**: `[nombrepanel].php` (ej: `skillspanel.php`)
- **Componentes Vue**: `[nombre].js` (ej: `skillstable.js`)
- **Strings**: `lang.*` key sin prefijos especiales

---

## 3. Página PHP Shell (Pattern)

### 3.1 Estructura Base

```php
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
 * [Descripción breve del módulo]
 *
 * @package    local_[modulename]
 * @copyright  [Año] [Autor]
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/grupomakro_core/locallib.php');
require_once($CFG->libdir . '/externallib.php');
$plugin_name = 'local_[modulename]';
$assetversion = !empty($CFG->themerev) ? (int)$CFG->themerev : 1;
require_login();

$PAGE->set_url($CFG->wwwroot . '/local/[modulename]/pages/[modulename]panel.php');

$context = context_system::instance();
$PAGE->set_context($context);
$PAGE->set_title(get_string('[modulename]_panel', $plugin_name));
$PAGE->set_heading(get_string('[modulename]_panel', $plugin_name));
$PAGE->set_pagelayout('admin');
```

### 3.2 Strings para JavaScript

```php
// Preparar strings para JS (traducibles)
$strings = new stdClass();
$strings->key1 = get_string('key1', $plugin_name);
$strings->key2 = get_string('key2', $plugin_name);
// ... agregar más keys según necesidad

$strings = json_encode($strings);
```

### 3.3 Configuración de Usuario y Theme

```php
$token = get_logged_user_token();
$themeToken = get_theme_token();

// Logo para PDFs
$logoUrl = $OUTPUT->get_logo_url();
if (!$logoUrl) {
    try {
        $theme = theme_config::load($CFG->theme);
        if (isset($theme->settings->logo) && !empty($theme->settings->logo)) {
            $logo = basename($theme->settings->logo);
            $logoUrl = new moodle_url('/theme/' . $CFG->theme . '/pix/static/' . $logo);
        }
    } catch (Exception $e) {
        // Ignore and continue without logo.
    }
}
$pdfLogoUrl = ($logoUrl instanceof moodle_url) ? $logoUrl->out(false) : '';
$pdfLogoUrl = json_encode($pdfLogoUrl);

// Verificar admin
$isAdmin = is_siteadmin() ? 'true' : 'false';
```

### 3.4 Header HTML y Estilos

```php
echo $OUTPUT->header();

echo <<<EOT
<link href="https://fonts.googleapis.com/css?family=Roboto:100,300,400,500,700,900" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.min.css" rel="stylesheet">
  <div id="gmk-app">
    <v-app class="transparent">
      <v-main>
        <div>
          <[component-name]></[component-name]>
        </div>
      </v-main>
    </v-app>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/vue@2.x/dist/vue.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/vuetify@2.x/dist/vuetify.js"></script>
  <script src="https://unpkg.com/axios/dist/axios.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
  <style>
    .theme--light.v-application{
      background: transparent !important;
    }
    /* Estilos customizados */
  </style>
  
  <script>
    var strings = $strings;
    var userToken = $token;
    var themeToken = $themeToken || null;
    var isAdmin = $isAdmin;
    var pdfLogoUrl = $pdfLogoUrl;
  </script>
EOT;
```

### 3.5 Carga de Componentes

```php
$PAGE->requires->js(new moodle_url('/local/[modulename]/js/components/[component1].js?v=' . $assetversion));
$PAGE->requires->js(new moodle_url('/local/[modulename]/js/components/[component2].js?v=' . $assetversion));
$PAGE->requires->js(new moodle_url('/local/[modulename]/js/app.js?v=' . $assetversion));

echo $OUTPUT->footer();
```

---

## 4. Componente Vue.js (Pattern)

### 4.1 Estructura Base

```javascript
Vue.component('[component-name]', {
    props: ['classId', 'otherProp'],  // Props desde PHP
    template: `
        <v-container fluid class="pa-0">
            <!-- Contenido del componente -->
        </v-container>
    `,
    data: function() {
        return {
            // Estado local
        };
    },
    computed: {
        // Propiedades computadas
    },
    watch: {
        // Watchers
    },
    created: function() {
        // Lifecycle hook
    },
    methods: {
        // Métodos
    }
});
```

### 4.2 Componente de Tarjeta KPI

```javascript
// Tarjeta de métricas en la parte superior
<v-row justify="center" class="my-2 mx-0 position-relative">
    <v-col cols="12" class="mb-4">
         <v-card class="pa-4 d-flex align-center" outlined style="border-left: 5px solid #4CAF50;">
            <div>
                <div class="text-overline mb-0">{[label]}</div>
                <div class="d-flex align-center" style="gap:6px">
                    <span class="text-h4 font-weight-bold success--text">{{ metricValue }}</span>
                    <v-tooltip max-width="340" right>
                        <template v-slot:activator="{ on, attrs }">
                            <v-icon v-bind="attrs" v-on="on" size="18" color="grey darken-1">mdi-information-outline</v-icon>
                        </template>
                        <div style="font-size:12px;line-height:1.6">
                            <div style="font-weight:700;margin-bottom:6px">{[title]}</div>
                            <div>{[description]}</div>
                        </div>
                    </v-tooltip>
                </div>
            </div>
            <v-spacer></v-spacer>
            <v-icon size="48" color="success" class="opacity-50">mdi-[icon]</v-icon>
         </v-card>
    </v-col>
</v-row>
```

### 4.3 Data Table con Búsqueda

```javascript
// Template de tabla
<v-data-table
    :headers="headers"
    :items="items"
    :options.sync="options"
    :server-items-length="totalItems"
    :loading="loading"
    class="elevation-1"
    :footer-props="{ 
        'items-per-page-text': lang.items_per_page,
        'items-per-page-options': [15, 25, 50],
    }"
>
    <template v-slot:top>
        <v-toolbar flat>
            <v-toolbar-title>{{ lang.title }}</v-toolbar-title>
            <v-spacer></v-spacer>
        </v-toolbar>
        
        <v-row justify="space-between" class="ma-0 mr-3 mb-2 align-center">
            <v-col cols="4">
                <v-text-field
                   v-model="searchInput"
                   append-icon="mdi-magnify"
                   :label="lang.search"
                   hide-details
                   outlined
                   dense
                   @keyup.enter="applySearch"
                ></v-text-field>
            </v-col>
            <v-col cols="auto" class="d-flex" style="gap: 8px;">
                <!-- Botones de acción -->
            </v-col>
        </v-row>
    </template>
    
    <!-- Columnas personalizadas -->
    <template v-slot:item.actions="{ item }">
        <v-btn icon small @click="editItem(item)">
            <v-icon small>mdi-pencil</v-icon>
        </v-btn>
    </template>
</v-data-table>
```

### 4.4 Data y Métodos

```javascript
data: function() {
    return {
        // Datos de tabla
        items: [],
        headers: [
            { text: 'Nombre', value: 'name' },
            { text: 'Estado', value: 'state' },
            { text: 'Fecha', value: 'date' },
            { text: 'Acciones', value: 'actions', sortable: false, align: 'center' },
        ],
        options: { page: 1, itemsPerPage: 15 },
        totalItems: 0,
        loading: false,
        searchInput: '',
        
        // Otros estados
        dialog: false,
        editMode: false,
    };
},
methods: {
    async getDataFromApi() {
        this.loading = true;
        try {
            const params = new URLSearchParams();
            params.append('action', 'local_[module]_get_data');
            params.append('sesskey', M.cfg.sesskey);
            params.append('search', this.searchInput);
            params.append('page', this.options.page - 1);
            params.append('limit', this.options.itemsPerPage);
            
            const response = await axios.post(
                `${M.cfg.wwwroot}/local/[module]/ajax.php`,
                params
            );
            
            if (response.data.status === 'success') {
                this.items = response.data.items;
                this.totalItems = response.data.total;
            }
        } catch (error) {
            console.error('Error:', error);
            Swal.fire('Error', error.message, 'error');
        } finally {
            this.loading = false;
        }
    },
    
    applySearch() {
        this.options.page = 1;
        this.getDataFromApi();
    },
    
    editItem(item) {
        this.selectedItem = Object.assign({}, item);
        this.dialog = true;
    },
}
```

---

## 5. Integración con API Moodle

### 5.1 Llamadas AJAX

```javascript
// Llamada básica
const params = new URLSearchParams();
params.append('action', 'local_[module]_[action]');
params.append('sesskey', M.cfg.sesskey);
// Parámetros adicionales...

const response = await axios.post(
    `${M.cfg.wwwroot}/local/[module]/ajax.php`,
    params
);

// Manejo de respuesta
if (response.data.status === 'success') {
    // Éxito
    this.items = response.data.result;
} else {
    // Error de negocio
    Swal.fire('Error', response.data.message, 'error');
}
```

### 5.2 Endpoints AJAX (PHP)

```php
// En ajax.php del módulo
function [module]_get_data() {
    global $DB, $USER;
    
    require_login();
    
    $search = optional_param('search', '', PARAM_TEXT);
    $page = optional_param('page', 0, PARAM_INT);
    $limit = optional_param('limit', 15, PARAM_INT);
    
    // Lógica de negocio...
    
    return [
        'status' => 'success',
        'items' => $items,
        'total' => $total,
    ];
}
```

---

## 6. UI Components Comunes

### 6.1 Card de Métricas

```html
<v-card class="pa-4 d-flex align-center" outlined style="border-left: 5px solid #4CAF50;">
    <div>
        <div class="text-overline mb-0">Label</div>
        <span class="text-h4 font-weight-bold success--text">{{ value }}</span>
    </div>
    <v-spacer></v-spacer>
    <v-icon size="48" color="success" class="opacity-50">mdi-icon</v-icon>
</v-card>
```

### 6.2 Dialog de Confirmación

```html
<v-dialog v-model="confirmDialog.show" max-width="400">
    <v-card>
        <v-card-title>{{ confirmDialog.title }}</v-card-title>
        <v-card-text>{{ confirmDialog.message }}</v-card-text>
        <v-card-actions>
            <v-spacer></v-spacer>
            <v-btn text @click="confirmDialog.show = false">Cancelar</v-btn>
            <v-btn color="primary" @click="confirmAction" :loading="confirmDialog.loading">
                Confirmar
            </v-btn>
        </v-card-actions>
    </v-card>
</v-dialog>
```

### 6.3 Snackbar para Notificaciones

```html
<v-snackbar v-model="snackbar.show" :color="snackbar.color" :timeout="3000">
    {{ snackbar.message }}
    <template v-slot:action="{ attrs }">
        <v-btn text v-bind="attrs" @click="snackbar.show = false">Cerrar</v-btn>
    </template>
</v-snackbar>
```

### 6.4 Toolbar con Filtros

```html
<v-toolbar flat color="white">
    <v-toolbar-title>{{ title }}</v-toolbar-title>
    <v-divider class="mx-4" inset vertical></v-divider>
    <v-spacer></v-spacer>
    <v-text-field
        v-model="search"
        prepend-icon="mdi-magnify"
        label="Buscar"
        single-line
        hide-details
    ></v-text-field>
    <v-btn icon @click="refresh">
        <v-icon>mdi-refresh</v-icon>
    </v-btn>
</v-toolbar>
```

---

## 7. Patrones de Diseño

### 7.1 Colors del Theme

| Propósito | Color | Usage |
|-----------|-------|-------|
| Success | `#4CAF50` | Border-left en cards positivas |
| Error | `#F44336` | Estados de error |
| Warning | `#FFC107` | Warnings |
| Info | `#2196F3` | Información |
| Primary | `#98ca3f` | Verde institucional ISI |

### 7.2 Iconos常用

| Función | Icono |
|---------|-------|
| Estudiantes | `mdi-account-check` |
| Agregar | `mdi-plus` |
| Editar | `mdi-pencil` |
| Eliminar | `mdi-delete` |
| Buscar | `mdi-magnify` |
| Refrescar | `mdi-refresh` |
| Exportar | `mdi-file-export` |
| Excel | `mdi-file-excel` |
| PDF | `mdi-file-pdf` |

### 7.3 Estilos CSS Custom

```css
.theme--light.v-application{
    background: transparent !important;
}
.panel-content{
    -webkit-column-gap: 1.25rem;
    -moz-column-gap: 1.25rem;
    column-gap: 1.25rem !important;
    grid-template-columns: repeat(4,1fr);
    display: grid;
    gap: 0.5rem;
}
ul.list{
    display: grid;
    grid-template-columns: repeat(1,1fr);
    margin-top: 1rem;
}
ul.list li{
    list-style: none;
    display: flex;
    align-items: center;
}
```

---

## 8. Checklist de Implementación

### 8.1 Página PHP

- [ ] Header de licencia Moodle
- [ ] Requires de librerías Moodle
- [ ] require_login()
- [ ] $PAGE->set_url()
- [ ] $PAGE->set_context()
- [ ] $PAGE->set_pagelayout('admin')
- [ ] Strings traducciones
- [ ] Token de usuario
- [ ] Logo para PDFs
- [ ] Verificación is_siteadmin()
- [ ] Include de CDNs (Vue, Vuetify, Axios, SweetAlert2)
- [ ] Estilos CSS custom
- [ ] Variables JS (strings, token, isAdmin)
- [ ] Carga de componentes JS
- [ ] Footer Moodle

### 8.2 Componente Vue

- [ ] Definición de props
- [ ] Template con Vuetify
- [ ] Data inicial
- [ ] computed properties
- [ ] methods (API calls, dialogs)
- [ ] watchers
- [ ] lifecycle hooks
- [ ] Integración con strings

### 8.3 AJAX

- [ ] Endpoint en ajax.php
- [ ] Manejo de parámetros
- [ ] Validación de permisos
- [ ] Respuesta JSON estructurada
- [ ] Manejo de errores

---

## 9. Ejemplo Completo: Estructura de Módulo Skeleton

```
local_skilltracker/
├── pages/
│   └── skillspanel.php
├── lang/
│   └── es/
│       └── local_skilltracker.php
├── js/
│   ├── app.js
│   └── components/
│       ├── skillstable.js
│       └── skillitem.js
├── ajax.php
├── lib.php
├── locallib.php
├── settings.php
├── version.php
└── db/
    └── install.xml
```

---

## 10. Referencias

- **Vue.js 2**: https://v2.vuejs.org/
- **Vuetify 2**: https://vuetifyjs.com/
- **Material Design Icons**: https://cdn.jsdelivr.net/npm/@mdi/font@6.x/css/materialdesignicons.min.css
- **Axios**: https://unpkg.com/axios/dist/axios.min.js
- **SweetAlert2**: https://cdn.jsdelivr.net/npm/sweetalert2@11

---

**Documento creado**: 2026
**Versión**: 1.0
**Para**: Módulos Moodle tipo SKILL