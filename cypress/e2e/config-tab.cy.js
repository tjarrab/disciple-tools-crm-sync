/**
 * E2E tests for the Configuration tab (Tab 1).
 */

describe( 'Configuration tab', () => {

    before( () => {
        cy.dtLogin();
    } );

    beforeEach( () => {
        cy.visitCRMSync( 'tab-config' );
    } );

// Basic rendering

    it( 'renders the Configuration tab heading', () => {
        cy.get( '#tab-config h2, #tab-config .tab-title' ).should( 'contain.text', 'Config' );
    } );

    it( 'shows the connector selector', () => {
        cy.get( '#tab-config select[name="active_connector"], #tab-config [data-testid="connector-select"]' )
            .should( 'exist' );
    } );

    it( 'shows a save-settings button', () => {
        cy.get( '#tab-config [type="submit"]' ).should( 'exist' );
    } );

// Credential form

    it( 'shows Base URL and API token inputs when Respond.io connector is selected', () => {
        // Select Respond.io if the selector exists; otherwise it may already be selected.
        cy.get( 'body' ).then( ( $body ) => {
            if ( $body.find( 'select[name="active_connector"]' ).length ) {
                cy.get( 'select[name="active_connector"]' ).select( 'respond_io' );
            }
        } );

        cy.get( '#tab-config input[name="connectors[respond_io][api_url]"]' )
            .should( 'exist' );
        cy.get( '#tab-config input[name="connectors[respond_io][api_token]"]' )
            .should( 'exist' );
    } );

// Test-connection button

    it( 'shows a test-connection button when credentials are present', () => {
        cy.get( '#tab-config [data-action="test-connection"], #tab-config button' )
            .contains( /test connection/i )
            .should( 'exist' );
    } );

    it( 'displays a result message after clicking test-connection', () => {
        cy.get( '#tab-config button' ).contains( /test connection/i ).click();
        // The button triggers an async REST call; wait for any result notice.
        cy.get( '#tab-config .notice, #tab-config [role="status"], #tab-config .test-result', {
            timeout: 15000,
        } ).should( 'be.visible' );
    } );

// Schema & field-mapping

    it( 'shows the schema-refresh button', () => {
        cy.get( '#tab-config button' ).contains( /refresh schema/i ).should( 'exist' );
    } );

    it( 'shows the field-mapping table header row', () => {
        cy.get( '#tab-config table thead th' ).should( 'have.length.at.least', 2 );
    } );
} );
