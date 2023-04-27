// Import Axios library to make HTTP requests
// NOTE: The Axios library is not included in this file and must be imported first
// with a script in the HTML.

// URL of the API to query.
const url = 'https://grupomakro-dev.soluttolabs.com/webservice/rest/server.php';

// Parameters to send with the API request.
const params = {
  wstoken: '943a55babc7ac145d983b6e3d7cd29df',
  moodlewsrestformat: 'json',
  wsfunction: 'local_soluttolms_core_get_theme_settings',
  themename: 'soluttolmsadmin'
};

// Variables that will store the colors obtained from the API response.
let primarycolor;
let darkPrimarycolor;
let secondarycolor;
let secondarycolordark;
let darkMode = false;

// Make a GET request to the API using Axios and the specified parameters.
axios.get(url, { params })
  .then(response => {
    // Extract the colors from the JSON response and assign them to the corresponding variables.
    const data = JSON.parse(response.data.themeobject);
    primarycolor = data.brandcolor;
    darkPrimarycolor = data.brandcolordark;
    secondarycolor = data.secondarycolor;
    secondarycolordark = data.secondarycolordark;
    
    // Get the value of the 'data-preset' attribute from the root element of the document.
    const preset = document.documentElement.getAttribute('data-preset');
    // If the 'data-preset' attribute value is 'dark', set the 'darkMode' variable to true.
    // This variable is later used to determine whether the dark or light theme should be applied.
    if (preset === 'dark') {
      darkMode = true;
    }
    
    // Create a Vue instance for the application.
    const app = new Vue({
      el: '#app',
      vuetify: new Vuetify({
        treeShake: true,
        theme: {
          dark: darkMode,
          themes: {
            light: {
              primary: primarycolor,
              secondary: secondarycolor,
              availabilityColor: '#0ed456'
            },
            dark: {
              primary: darkPrimarycolor,
              secondary: secondarycolordark,
              availabilityColor: '#0ed456'
            }
          },
        },
      }),
      data: {
      },
      mounted() {},
      created() {},
      methods: {},
    });
    // Set up a MutationObserver to detect changes in light/dark mode
    const observer = new MutationObserver((mutations) => {
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