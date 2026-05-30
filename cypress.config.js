const { defineConfig } = require( 'cypress' );

module.exports = defineConfig( {
    e2e: {
        // Base URL is read from the CYPRESS_BASE_URL env var so local and CI
        // environments can point at different WordPress instances without
        // touching this file.
        baseUrl: process.env.CYPRESS_BASE_URL || 'http://localhost:8080',
        specPattern: 'cypress/e2e/**/*.cy.js',
        supportFile: 'cypress/support/e2e.js',
        viewportWidth: 1280,
        viewportHeight: 800,
        // Keep videos off by default; enable in CI by setting CYPRESS_VIDEO=true
        video: process.env.CYPRESS_VIDEO === 'true',
        screenshotOnRunFailure: true,
        defaultCommandTimeout: 10000,
    },
    env: {
        // Override via cypress.env.json (git-ignored) or CI environment variables.
        WP_USERNAME: process.env.CYPRESS_WP_USERNAME || 'admin',
        WP_PASSWORD: process.env.CYPRESS_WP_PASSWORD || 'password',
        PLUGIN_SLUG: 'disciple-tools-crm-sync',
    },
} );
