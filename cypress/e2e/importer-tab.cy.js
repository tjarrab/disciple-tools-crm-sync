/**
 * E2E tests for the Contact Importer tab (Tab 2 — React SPA).
 */

describe( 'Contact Importer tab (React SPA)', () => {

    before( () => {
        cy.dtLogin();
    } );

    beforeEach( () => {
        cy.visitCRMSync( 'tab-importer' );
    } );

// SPA mounting

    it( 'mounts the React app container', () => {
        cy.get( '#dt-crm-sync-importer-root, [data-testid="importer-app"]', {
            timeout: 15000,
        } ).should( 'exist' );
    } );

    it( 'displays the filter bar', () => {
        cy.get( '[data-testid="filter-bar"], .filter-bar, form.dt-crm-sync-filter', {
            timeout: 15000,
        } ).should( 'be.visible' );
    } );

// Filter controls

    it( 'has a search input in the filter bar', () => {
        cy.get( 'input[placeholder*="Search"], input[name="search"]' ).should( 'exist' );
    } );

    it( 'shows an apply-filter / fetch-contacts button', () => {
        cy.get( 'button' ).contains( /fetch|apply|search/i ).should( 'exist' );
    } );

// Contact table & pagination

    it( 'renders the contact table header after fetching contacts', () => {
        // Intercept the REST contacts call so we don't need a live CRM.
        cy.intercept( 'POST', '**/disciple-tools-crm-sync/v1/contacts', {
            statusCode: 200,
            body: {
                data: [
                    { id: 1, firstName: 'Alice', lastName: 'Test', phone: '+1555000001', email: 'alice@example.com' },
                    { id: 2, firstName: 'Bob',   lastName: 'Test', phone: '+1555000002', email: 'bob@example.com' },
                ],
                cursor: { next: null },
                total: 2,
            },
        } ).as( 'getContacts' );

        cy.get( 'button' ).contains( /fetch|apply|search/i ).click();
        cy.wait( '@getContacts' )
            .its( 'request.body' )
            .should( 'deep.equal', { filter_params: {} } );

        cy.get( 'table thead th, [data-testid="contact-table"] th' )
            .should( 'have.length.at.least', 2 );
    } );

    it( 'displays contact rows returned by the API', () => {
        cy.intercept( 'POST', '**/disciple-tools-crm-sync/v1/contacts', {
            statusCode: 200,
            body: {
                data: [
                    { id: 1, firstName: 'Alice', lastName: 'Test', phone: '', email: '' },
                ],
                cursor: { next: null },
                total: 1,
            },
        } ).as( 'getContacts' );

        cy.get( 'button' ).contains( /fetch|apply|search/i ).click();
        cy.wait( '@getContacts' );

        cy.get( 'table tbody tr, [data-testid="contact-row"]' ).should( 'have.length.at.least', 1 );
        cy.contains( 'Alice' ).should( 'be.visible' );
    } );

// Import button

    it( 'enables the import button after at least one row is selected', () => {
        cy.intercept( 'POST', '**/disciple-tools-crm-sync/v1/contacts', {
            statusCode: 200,
            body: {
                data: [ { id: 3, firstName: 'Carol', lastName: 'Test', phone: '', email: '' } ],
                cursor: { next: null },
                total: 1,
            },
        } ).as( 'getContacts' );

        cy.get( 'button' ).contains( /fetch|apply|search/i ).click();
        cy.wait( '@getContacts' );

        // Wait for React to commit the table rows before interacting with checkboxes.
        cy.get( '#dt-crm-sync-importer-root table tbody tr, [data-testid="contact-row"]' ).should( 'have.length.at.least', 1 );

        // Check the first row's checkbox.
        cy.get( '#dt-crm-sync-importer-root table tbody tr:first-child input[type="checkbox"], [data-testid="contact-row"]:first-child input[type="checkbox"]' ).check();

        cy.get( '[data-testid="import-button"], button' ).contains( /^import/i ).should( 'not.be.disabled' );
    } );

    it( 'shows a success or progress notice after clicking import', () => {
        cy.intercept( 'POST', '**/disciple-tools-crm-sync/v1/contacts', {
            body: {
                data: [ { id: 4, firstName: 'Dan', lastName: 'Test', phone: '', email: '' } ],
                cursor: { next: null },
                total: 1,
            },
        } ).as( 'getContacts' );
        cy.intercept( 'POST', '**/disciple-tools-crm-sync/v1/import', {
            statusCode: 200,
            body: { status: 'queued', batches: 1 },
        } ).as( 'doImport' );

        cy.get( 'button' ).contains( /fetch|apply|search/i ).click();
        cy.wait( '@getContacts' );

        cy.get( '#dt-crm-sync-importer-root table tbody tr:first-child input[type="checkbox"]' ).check();
        cy.get( 'button' ).contains( /^import/i ).click();
        cy.wait( '@doImport' );

        cy.get( '[role="status"], .notice, [data-testid="import-notice"]', { timeout: 10000 } )
            .should( 'be.visible' );
    } );

    it( 'clears the success banner when a second import starts', () => {
        cy.intercept( 'POST', '**/disciple-tools-crm-sync/v1/contacts', {
            statusCode: 200,
            body: {
                data: [ { id: 5, firstName: 'Eve', lastName: 'Test', phone: '', email: '' } ],
                cursor: { next: null },
                total: 1,
            },
        } ).as( 'getContacts' );

        // First import resolves immediately.
        cy.intercept( 'POST', '**/disciple-tools-crm-sync/v1/import', {
            statusCode: 200,
            body: { status: 'queued', batches: 1 },
        } ).as( 'firstImport' );

        cy.get( 'button' ).contains( /fetch|apply|search/i ).click();
        cy.wait( '@getContacts' );

        cy.get( '#dt-crm-sync-importer-root table tbody tr:first-child input[type="checkbox"]' ).check();
        cy.get( 'button' ).contains( /^import/i ).click();
        cy.wait( '@firstImport' );

        // Success banner should be visible after the first import.
        cy.get( '.notice-success', { timeout: 10000 } ).should( 'be.visible' );

        // Second import is delayed so we can assert state while it's in-flight.
        cy.intercept( 'POST', '**/disciple-tools-crm-sync/v1/import', ( req ) => {
            req.reply( ( res ) => {
                res.setDelay( 800 );
                res.send( { statusCode: 200, body: { status: 'queued', batches: 1 } } );
            } );
        } ).as( 'secondImport' );

        cy.get( 'button' ).contains( /^import/i ).click();

        // Banner must be gone while the second request is still in-flight.
        cy.get( '.notice-success' ).should( 'not.exist' );

        cy.wait( '@secondImport' );

        // Banner returns once the second import resolves.
        cy.get( '.notice-success', { timeout: 10000 } ).should( 'be.visible' );
    } );

// Session-expired banner

    it( 'shows a session-expired banner when the API returns 401', () => {
        cy.intercept( 'POST', '**/disciple-tools-crm-sync/v1/contacts', {
            statusCode: 401,
            body: { code: 'rest_not_logged_in' },
        } ).as( 'unauthorized' );

        cy.get( 'button' ).contains( /fetch|apply|search/i ).click();
        cy.wait( '@unauthorized' );

        cy.get( '[data-testid="session-expired-banner"], .session-expired' ).should( 'be.visible' );
    } );

// Network / server error states

    it( 'shows an error notice when a network failure occurs during contact fetch', () => {
        cy.intercept( 'POST', '**/disciple-tools-crm-sync/v1/contacts', {
            forceNetworkError: true,
        } ).as( 'networkError' );

        cy.get( 'button' ).contains( /fetch|apply|search/i ).click();
        cy.wait( '@networkError' );

        cy.get( '.notice.notice-error', { timeout: 10000 } ).should( 'be.visible' );
    } );

    it( 'shows an error notice when the server returns a 500 with an error body', () => {
        cy.intercept( 'POST', '**/disciple-tools-crm-sync/v1/contacts', {
            statusCode: 500,
            body: { error: 'Internal Server Error' },
        } ).as( 'serverError' );

        cy.get( 'button' ).contains( /fetch|apply|search/i ).click();
        cy.wait( '@serverError' );

        cy.get( '.notice.notice-error', { timeout: 10000 } ).should( 'be.visible' );
    } );

    it( 'shows an error notice when the contacts endpoint returns a non-JSON 200 body', () => {
        // Regression test for the response.json() await bug: without `return await`,
        // a SyntaxError from parsing a non-JSON body (e.g. an HTML maintenance page
        // served with a 200) escapes the try/catch and reaches the caller as a raw
        // rejection, bypassing all typed error handling and leaving the UI stuck.
        cy.intercept( 'POST', '**/disciple-tools-crm-sync/v1/contacts', {
            statusCode: 200,
            headers: { 'Content-Type': 'text/html' },
            body: '<html><body>Service temporarily unavailable</body></html>',
        } ).as( 'nonJsonResponse' );

        cy.get( 'button' ).contains( /fetch|apply|search/i ).click();
        cy.wait( '@nonJsonResponse' );

        cy.get( '.notice.notice-error', { timeout: 10000 } ).should( 'be.visible' );
    } );

// Session expiry during an active import

    it( 'shows a session-expired banner when the import POST returns 401', () => {
        cy.intercept( 'POST', '**/disciple-tools-crm-sync/v1/contacts', {
            statusCode: 200,
            body: {
                data: [ { id: 20, firstName: 'Grace', lastName: 'Test', phone: '', email: '' } ],
                cursor: { next: null },
                total: 1,
            },
        } ).as( 'getContacts' );

        cy.intercept( 'POST', '**/disciple-tools-crm-sync/v1/import', {
            statusCode: 401,
            body: { code: 'rest_not_logged_in' },
        } ).as( 'importExpired' );

        cy.get( 'button' ).contains( /fetch|apply|search/i ).click();
        cy.wait( '@getContacts' );
        cy.get( '#dt-crm-sync-importer-root table tbody tr, [data-testid="contact-row"]' ).should( 'have.length.at.least', 1 );

        cy.get( '#dt-crm-sync-importer-root table tbody tr:first-child input[type="checkbox"], [data-testid="contact-row"]:first-child input[type="checkbox"]' ).check();
        cy.get( '[data-testid="import-button"], button' ).contains( /^import/i ).click();
        cy.wait( '@importExpired' );

        cy.contains( 'Session Expired', { timeout: 10000 } ).should( 'be.visible' );
    } );

// Multi-select then deselect

    it( 'disables the import button when all selected rows are deselected', () => {
        cy.intercept( 'POST', '**/disciple-tools-crm-sync/v1/contacts', {
            statusCode: 200,
            body: {
                data: [
                    { id: 30, firstName: 'Hank', lastName: 'Test', phone: '', email: '' },
                    { id: 31, firstName: 'Iris', lastName: 'Test', phone: '', email: '' },
                ],
                cursor: { next: null },
                total: 2,
            },
        } ).as( 'getContacts' );

        cy.get( 'button' ).contains( /fetch|apply|search/i ).click();
        cy.wait( '@getContacts' );
        cy.get( '#dt-crm-sync-importer-root table tbody tr, [data-testid="contact-row"]' ).should( 'have.length', 2 );

        // Select all rows — import button should become enabled.
        cy.get( '#dt-crm-sync-importer-root table tbody tr input[type="checkbox"], [data-testid="contact-row"] input[type="checkbox"]' ).check();
        cy.get( '[data-testid="import-button"], button' ).contains( /^import/i ).should( 'not.be.disabled' );

        // Deselect all rows — import button should become disabled.
        cy.get( '#dt-crm-sync-importer-root table tbody tr input[type="checkbox"], [data-testid="contact-row"] input[type="checkbox"]' ).uncheck();
        cy.get( '[data-testid="import-button"], button' ).contains( /^import/i ).should( 'be.disabled' );
    } );
} );

// Missing window.dtCrmSync configuration
//
// This describe block uses a custom cy.visit() with onBeforeLoad so it runs
// independently of the shared beforeEach above.

describe( 'Contact Importer — missing window.dtCrmSync', () => {

    before( () => {
        cy.dtLogin();
    } );

    it( 'shows a configuration-unavailable notice when window.dtCrmSync is absent', () => {
        // Define dtCrmSync as non-writable before page scripts run so that the
        // wp_localize_script inline assignment cannot overwrite it.  This simulates
        // the real failure mode where the script is loaded out of order or stripped
        // by a caching plugin.
        cy.visit( '/wp-admin/admin.php?page=disciple-tools-crm-sync&tab=importer', {
            onBeforeLoad( win ) {
                Object.defineProperty( win, 'dtCrmSync', {
                    configurable: false,
                    writable: false,
                    value: null,
                } );
            },
        } );

        cy.get( '.notice.notice-error', { timeout: 10000 } )
            .should( 'be.visible' )
            .and( 'contain.text', 'CRM Sync configuration is unavailable' );
    } );
} );
