// Import Axios library to make HTTP requests
// NOTE: The Axios library is not included in this file and must be imported first
// with a script in the HTML.

// URL of the API to query.
const wsUrl = window.location.origin + '/webservice/rest/server.php';

const wsStaticParams = {
  wstoken: window.token,
  moodlewsrestformat: 'json',
}
// Parameters to send with the API request.
//
// wstoken: the admin page sets window.themeToken (= sesskey) in the
// inline <script>. Older pages or pages that don't set it leave it
// undefined; in that case initVueApp() short-circuits to the
// 'useThemeDefaults' branch and emits a single warning into the
// console before mounting the app normally. Both names are tolerated
// for backward compatibility with pages that historically set
// window.token.
const getThemeSettingsParams = {
  wstoken: window.themeToken || window.token,
  moodlewsrestformat: 'json',
  wsfunction: 'local_soluttolms_core_get_theme_settings',
  themename: 'soluttolmsadmin'
};

// Variables that will store the colors obtained from the API response.
let primarycolor;
let darkPrimarycolor;
let secondarycolor;
let secondarycolordark;
let bgcolordark;
let darkMode = false;

function mountVueApp() {
  // Verify that the DOM element exists before creating Vue instance
  const appElement = document.querySelector('#gmk-app');
  if (!appElement) {
    console.error('Cannot find #gmk-app element. Vue initialization aborted.');
    return;
  }

  // Surface any setup error to the page itself so the user does not get a
  // blank screen. The page may already have a partial render (Moodle
  // chrome, the inline <div id="gmk-app">); we replace the contents of
  // that div with a styled error card so the operator can read the
  // failure without opening devtools.
  function showFatal(message) {
    try {
      const html = '<div style="padding:24px;margin:16px;border:2px solid #b71c1c;border-radius:6px;background:#ffebee;color:#b71c1c;font-family:system-ui">'
        + '<h3 style="margin:0 0 8px 0">Vue app failed to mount</h3>'
        + '<pre style="white-space:pre-wrap;margin:0;font-family:monospace">' + String(message).replace(/[<>&]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[c])) + '</pre>'
        + '</div>';
      appElement.innerHTML = html;
    } catch (e) { /* swallow */ }
  }
  window.addEventListener('error', function (ev) {
    if (ev && ev.error) {
      console.error('global error:', ev.error);
      showFatal(ev.error.stack || ev.error.message || String(ev.error));
    }
  });
  window.addEventListener('unhandledrejection', function (ev) {
    if (ev && ev.reason) {
      console.error('unhandled rejection:', ev.reason);
      showFatal((ev.reason && ev.reason.stack) || (ev.reason && ev.reason.message) || String(ev.reason));
    }
  });

  // Add SweetAlert2 to Vue prototype
  if (typeof Swal !== 'undefined') {
    window.Vue.prototype.$swal = Swal;
  } else {
    console.warn('SweetAlert2 is not loaded. Alerts will not work.');
  }

  let app;
  try {
    app = new window.Vue({
    el: '#gmk-app',
    vuetify: new window.Vuetify({
      treeShake: true,
      theme: {
        dark: darkMode,
        themes: {
          light: {
            primary: primarycolor,
            secondary: secondarycolor,
            availabilityColor: '#0ed456',
            success: '#3cd4a0',
            base: '#f8f9fa'
          },
          dark: {
            primary: darkPrimarycolor,
            secondary: secondarycolordark,
            availabilityColor: '#0ed456',
            success: '#3cd4a0',
            base: bgcolordark
          }
        },
      },
    }),
    data: {},
    mounted() {},
    created() {},
    methods: {},
  });
  } catch (e) {
    console.error('mountVueApp failed:', e);
    showFatal(e && (e.stack || e.message) || String(e));
    return;
  }

  // Set up a MutationObserver to detect changes in light/dark mode
  const observer = new window.MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
      if (mutation.attributeName === 'data-preset') {
        // Update the Vuetify theme based on the current light/dark mode.
        const newValue = mutation.target.getAttribute('data-preset');
        app.$vuetify.theme.dark = newValue === 'dark';
      }
    });
  });

  observer.observe(document.documentElement, { attributes: true });
}

// Wrap initialization in DOMContentLoaded to ensure DOM is ready
function initVueApp() {
  // Skip the theme fetch entirely if there is no usable token (avoids a
  // SyntaxError cascade: WS returns "undefined" string, JSON.parse explodes,
  // Vue component never gets window.strings etc. and surfaces a confusing
  // "Cannot read properties of undefined").
  const hasThemeToken = typeof getThemeSettingsParams.wstoken === 'string'
    && getThemeSettingsParams.wstoken
    && getThemeSettingsParams.wstoken !== 'undefined'
    && getThemeSettingsParams.wstoken !== '""';

  if (!hasThemeToken) {
    console.warn('[initVueApp] no themeToken; skipping theme fetch and using defaults.');
    applyThemeDefaults();
    mountVueApp();
    return;
  }

  // Make a GET request to the API using Axios and the specified parameters.
  window.axios.get(wsUrl, { params: getThemeSettingsParams })
    .then(response => {
      // Guard against non-JSON themeobject (e.g. "undefined" when the WS
      // returns an error string). Falling back to defaults is safer than
      // throwing a SyntaxError that breaks every Vue component downstream.
      if (typeof response.data.themeobject !== 'string'
          || !response.data.themeobject
          || response.data.themeobject === 'undefined') {
        throw new Error('themeobject missing or invalid');
      }
      const data = JSON.parse(response.data.themeobject);
      primarycolor = data.brandcolor;
      darkPrimarycolor = data.brandcolordark;
      secondarycolor = data.secondarycolor;
      secondarycolordark = data.secondarycolordark;
      bgcolordark = data.bgcolordark

      // Get the value of the 'data-preset' attribute from the root element of the document.
      const preset = document.documentElement.getAttribute('data-preset');
      // If the 'data-preset' attribute value is 'dark', set the 'darkMode' variable to true.
      // This variable is later used to determine whether the dark or light theme should be used.
      if (preset === 'dark') {
        darkMode = true;
      }
      mountVueApp();
    })
    .catch(error => {
      console.error('[initVueApp] theme fetch failed:', error);
      applyThemeDefaults();
      mountVueApp();
    });
}

function applyThemeDefaults() {
  primarycolor = primarycolor || '#1976d2';
  darkPrimarycolor = darkPrimarycolor || '#1e88e5';
  secondarycolor = secondarycolor || '#424242';
  secondarycolordark = secondarycolordark || '#bdbdbd';
  bgcolordark = bgcolordark || '#121212';
  const preset = document.documentElement.getAttribute('data-preset');
  if (preset === 'dark') {
    darkMode = true;
  }
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initVueApp);
} else {
  // DOM is already ready, initialize immediately
  initVueApp();
}