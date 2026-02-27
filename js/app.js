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
const getThemeSettingsParams = {
  wstoken: window.themeToken,
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

// Wrap initialization in DOMContentLoaded to ensure DOM is ready
function initVueApp() {
  // Make a GET request to the API using Axios and the specified parameters.
  window.axios.get(wsUrl, { params: getThemeSettingsParams })
    .then(response => {
      // Extract the colors from the JSON response and assign them to the corresponding variables.
      const data = JSON.parse(response.data.themeobject);
      primarycolor = data.brandcolor;
      darkPrimarycolor = data.brandcolordark;
      secondarycolor = data.secondarycolor;
      secondarycolordark = data.secondarycolordark;
      bgcolordark = data.bgcolordark

      // Get the value of the 'data-preset' attribute from the root element of the document.
      const preset = document.documentElement.getAttribute('data-preset');
      // If the 'data-preset' attribute value is 'dark', set the 'darkMode' variable to true.
      // This variable is later used to determine whether the dark or light theme should be applied.
      if (preset === 'dark') {
        darkMode = true;
      }

      // Verify that the DOM element exists before creating Vue instance
      const appElement = document.querySelector('#gmk-app');
      if (!appElement) {
        console.error('Cannot find #gmk-app element. Vue initialization aborted.');
        return;
      }

      // Add SweetAlert2 to Vue prototype
      if (typeof Swal !== 'undefined') {
        window.Vue.prototype.$swal = Swal;
      } else {
        console.warn('SweetAlert2 is not loaded. Alerts will not work.');
      }

      // Create a Vue instance for the application.
      const app = new window.Vue({
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
      data: {
      },
      mounted() { },
      created() { },
      methods: {},
    });
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
    })
    .catch(error => {
      console.error(error);
    });
}

// Initialize when DOM is ready
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', initVueApp);
} else {
  // DOM is already ready, initialize immediately
  initVueApp();
}