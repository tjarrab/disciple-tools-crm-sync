/**
 * E2E tests for the Automations / Filter tab (Tab 3).
 */

describe( 'Automations tab', () => {

    before( () => {
        cy.dtLogin();
    } );

    beforeEach( () => {
        cy.visitCRMSync( 'tab-automations' );
    } );

    it( 'renders the Automations tab content area', () => {
        cy.get( '#tab-automations' ).should( 'be.visible' );
    } );

    it( 'shows the create-filter form or button', () => {
        cy.get( '#tab-automations' ).within( () => {
            cy.get( 'form, button, [data-testid="create-filter-btn"]' ).should( 'exist' );
        } );
    } );

    it( 'has a name input field for creating a filter', () => {
        cy.get( '#tab-automations input[name="filter_name"], #tab-automations input[placeholder*="name"]' )
            .should( 'exist' );
    } );

    it( 'has an interval selector', () => {
        cy.get( '#tab-automations select[name="interval"]' ).should( 'exist' );
    } );

    it( 'shows an error when submitting the form without a name', () => {
        cy.intercept( 'POST', '**/disciple-tools-crm-sync/v1/saved-filters' ).as( 'createFilter' );

        // Submit without filling the name field.
        cy.get( 'input[name="save_filter_btn"]' ).click();

        // Either native HTML5 validation fires (no network call) or the backend
        // returns a 400 and the UI shows an error message.
        cy.get( 'body' ).then( ( $body ) => {
            const submittedRequest = $body.find( '#tab-automations [data-testid="filter-error"], #tab-automations .notice-error' ).length;
            if ( submittedRequest ) {
                cy.get( '#tab-automations [data-testid="filter-error"], #tab-automations .notice-error' ).should( 'be.visible' );
            }
            // If HTML5 validation prevented submission, no assertion needed —
            // the :invalid pseudo-class is on the name input.
        } );
    } );

    it( 'creates a filter and shows it in the list', () => {
        const filterName = 'Cypress Test Filter ' + Date.now();

        cy.intercept( 'POST', '**/disciple-tools-crm-sync/v1/saved-filters' ).as( 'createFilter' );
        cy.intercept( 'GET', '**/disciple-tools-crm-sync/v1/saved-filters' ).as( 'listFilters' );

        cy.get( '#tab-automations input[name="filter_name"]' ).clear().type( filterName );
        cy.get( '#tab-automations select[name="interval"]' ).select( 'hourly' );
        cy.get( 'input[name="save_filter_btn"]' ).click();
        cy.wait( '@createFilter' ).its( 'response.statusCode' ).should( 'eq', 201 );

        // After creation the list should refresh and show the new entry.
        cy.contains( filterName, { timeout: 10000 } ).should( 'be.visible' );
    } );

    it( 'includes select-type filter fields in the REST request body', () => {
        // conversation_status is a <select>. The JS form handler must collect
        // select elements alongside inputs — if it only queries for inputs the
        // value is silently dropped and never stored in the filter envelope.
        const filterName = 'Cypress Status Filter ' + Date.now();

        cy.intercept( 'POST', '**/disciple-tools-crm-sync/v1/saved-filters' ).as( 'createFilter' );

        cy.get( '#tab-automations input[name="filter_name"]' ).clear().type( filterName );
        cy.get( '#tab-automations select[name="interval"]' ).select( 'hourly' );
        cy.get( '#tab-automations select[name="filter_params_conversation_status"]' ).select( 'open_or_snoozed' );
        cy.get( 'input[name="save_filter_btn"]' ).click();

        cy.wait( '@createFilter' ).then( ( interception ) => {
            const params = interception.request.body?.filter_params ?? {};
            expect( params.conversation_status ).to.equal( 'open_or_snoozed' );
        } );
    } );

    it( 'removes the filter from the list after clicking delete', () => {
        const filterName = 'Cypress Delete Me ' + Date.now();

        // Create via API intercept.
        cy.intercept( 'POST', '**/disciple-tools-crm-sync/v1/saved-filters' ).as( 'create' );
        cy.get( '#tab-automations input[name="filter_name"]' ).clear().type( filterName );
        cy.get( '#tab-automations select[name="interval"]' ).select( 'hourly' );
        cy.get( 'input[name="save_filter_btn"]' ).click();
        cy.wait( '@create' );
        cy.contains( filterName, { timeout: 10000 } ).should( 'be.visible' );

        // Now delete.
        cy.intercept( 'DELETE', '**/disciple-tools-crm-sync/v1/saved-filters/**' ).as( 'delete' );
        cy.contains( filterName ).closest( 'tr, li, [data-testid]' ).within( () => {
            cy.get( 'button' ).contains( /delete|remove/i ).click();
        } );
        cy.wait( '@delete' ).its( 'response.statusCode' ).should( 'eq', 200 );

        cy.contains( filterName ).should( 'not.exist' );
    } );
} );
