/**
 * E2E tests for cross-tab navigation and browser history behaviour.
 */

describe( 'Tab navigation — browser history', () => {

    before( () => {
        cy.dtLogin();
    } );

    it( 'restores the previous tab when the browser back button is used', () => {
        cy.visit( '/wp-admin/admin.php?page=disciple-tools-crm-sync&tab=automations' );
        cy.get( '.dt-crm-sync-admin', { timeout: 10000 } ).should( 'be.visible' );
        cy.get( '#tab-automations' ).should( 'be.visible' );
        cy.get( '#tab-config' ).should( 'not.be.visible' );

        cy.visit( '/wp-admin/admin.php?page=disciple-tools-crm-sync&tab=config' );
        cy.get( '#tab-config' ).should( 'be.visible' );
        cy.get( '#tab-automations' ).should( 'not.be.visible' );

        cy.go( 'back' );

        cy.get( '#tab-automations', { timeout: 10000 } ).should( 'be.visible' );
        cy.get( '#tab-config' ).should( 'not.be.visible' );
    } );
} );
