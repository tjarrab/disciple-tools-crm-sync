/**
 * Custom Cypress commands for the Disciple.Tools CRM Sync plugin.
 */

/**
 * Log in to WordPress as the given user.
 *
 * Usage: cy.dtLogin()                     // uses env WP_USERNAME / WP_PASSWORD
 *        cy.dtLogin('editor', 'pass123')  // explicit credentials
 */
Cypress.Commands.add( 'dtLogin', ( username, password ) => {
    const user = username || Cypress.env( 'WP_USERNAME' );
    const pass = password || Cypress.env( 'WP_PASSWORD' );

    // cy.session() caches the auth cookies so only the first call per spec run
    // (or first call across specs when cacheAcrossSpecs is true) performs the
    // full login.  Subsequent calls restore the cookies instantly, surviving
    // Cypress testIsolation which clears cookies before each test.
    cy.session(
        [ user, pass ],
        () => {
            cy.visit( '/wp-login.php' );
            cy.get( '#user_login' ).clear().type( user );
            cy.get( '#user_pass' ).clear().type( pass, { log: false } );
            cy.get( '#wp-submit' ).click();

            // After submit, detect 2FA prompts or off-domain SSO redirects.
            cy.url( { timeout: 10000 } ).then( ( url ) => {
                if ( ! url.startsWith( Cypress.config( 'baseUrl' ) ) ) {
                    throw new Error(
                        `cy.dtLogin: Redirected off-domain to "${ url }". SSO or external auth is active — provide a pre-authenticated session cookie.`
                    );
                }
            } );

            cy.get( 'body' ).then( ( $body ) => {
                const has2FA = $body.find(
                    'input[name="two_factor_code"], input[name="authcode"], #two-factor-prompt, .two-factor-prompt'
                ).length > 0;

                if ( has2FA ) {
                    throw new Error(
                        'cy.dtLogin: 2FA prompt detected. Disable 2FA for the test user or supply a pre-authenticated cookie via CYPRESS_WP_COOKIE.'
                    );
                }
            } );

            // Navigate to the plugin page to verify the session is fully active.
            cy.visit( '/wp-admin/admin.php?page=disciple-tools-crm-sync' );
            cy.get( '#wpadminbar', { timeout: 15000 } ).should( 'be.visible' );
        },
        { cacheAcrossSpecs: true }
    );
} );

/**
 * Navigate to the CRM Sync admin page and, optionally, a specific tab.
 *
 * Usage: cy.visitCRMSync()               // lands on the default tab
 *        cy.visitCRMSync('tab-config')   // clicks the "Configuration" tab
 *        cy.visitCRMSync('tab-automations')
 *
 * Requires an active WordPress session (call cy.dtLogin() first).
 */
Cypress.Commands.add( 'visitCRMSync', ( tabId ) => {
    // Always restore (or create) the auth session before navigating.  With
    // Cypress testIsolation=true cookies are cleared before each `it()` block,
    // so cy.dtLogin() must be called here — not only in the spec's before().
    // cy.session() returns instantly from cache when the session already exists.
    cy.dtLogin();
    cy.visit( '/wp-admin/admin.php?page=disciple-tools-crm-sync' );
    cy.get( '.dt-crm-sync-admin', { timeout: 10000 } ).should( 'be.visible' );

    if ( tabId ) {
        cy.get( `[data-tab="${tabId}"]` ).click();
        cy.get( `#${tabId}` ).should( 'be.visible' );
    }
} );
