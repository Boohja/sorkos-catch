window.addEventListener('load', () => {
  window.ui = SwaggerUIBundle({
    url: '/docs/api/openapi.json',
    dom_id: '#swagger-ui',
    deepLinking: true,
    displayRequestDuration: true,
    filter: true,
    persistAuthorization: false,
    tryItOutEnabled: false,
    validatorUrl: null,
    presets: [
      SwaggerUIBundle.presets.apis,
      SwaggerUIStandalonePreset,
    ],
    plugins: [SwaggerUIBundle.plugins.DownloadUrl],
    layout: 'StandaloneLayout',
  });
});
