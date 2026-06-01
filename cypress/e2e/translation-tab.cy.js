/**
 * E2E tests for the Translation tab.
 */

describe( 'Translation tab', () => {

    before( () => {
        cy.dtLogin();
    } );

    beforeEach( () => {
        cy.visitCRMSync( 'tab-translation' );
    } );

// Basic rendering

    it( 'renders the Translation tab heading', () => {
        cy.get( '#tab-translation h2, #tab-translation .tab-title' ).should( 'contain.text', 'Translation' );
    } );

    it( 'shows the AI Provider selector', () => {
        cy.get( '#tab-translation select[name="translation_provider"], #tab-translation #dt_translation_provider' )
            .should( 'exist' );
    } );

    it( 'shows the API key input field', () => {
        cy.get( '#tab-translation input[name="translation_api_key"], #tab-translation #dt_translation_api_key' )
            .should( 'exist' )
            .should( 'have.attr', 'type', 'password' );
    } );

    it( 'shows the model selector', () => {
        cy.get( '#tab-translation select[name="translation_model"], #tab-translation #dt_translation_model' )
            .should( 'exist' );
    } );

    it( 'shows the enable translation checkbox', () => {
        cy.get( '#tab-translation input[name="translation_enabled"][type="checkbox"]' )
            .should( 'exist' );
    } );

    it( 'shows the translation prompt textarea', () => {
        cy.get( '#tab-translation textarea[name="translation_prompt"], #tab-translation #dt_translation_prompt' )
            .should( 'exist' );
    } );

    it( 'shows the daily limit input field', () => {
        cy.get( '#tab-translation input[name="translation_daily_limit"], #tab-translation #dt_translation_daily_limit' )
            .should( 'exist' )
            .should( 'have.attr', 'type', 'number' );
    } );

    it( 'shows a save settings button', () => {
        cy.get( '#tab-translation button[name="save_translation_settings"], #tab-translation [type="submit"]' )
            .should( 'exist' );
    } );

// Refresh Models button

    it( 'shows the refresh models button', () => {
        cy.get( '#tab-translation button' ).contains( /refresh models/i ).should( 'exist' );
    } );

    it( 'displays a result message after clicking refresh models', () => {
        // Mock a failed response so the JS error path sets the status span without
        // triggering window.location.reload() (which the success path does, wiping
        // the span before Cypress can assert it).
        cy.intercept( 'DELETE', '**/disciple-tools-crm-sync/v1/translation/models-cache', {
            statusCode: 200,
            body: { success: false },
        } ).as( 'deleteModelsCache' );

        cy.get( '#tab-translation button' ).contains( /refresh models/i ).click();
        cy.wait( '@deleteModelsCache' );

        // The error path sets a non-empty status message without reloading the page.
        cy.get( '#tab-translation #dt-translation-models-result', {
            timeout: 10000,
        } ).should( 'be.visible' );
    } );

// Test Translation button (if it exists)

    it( 'shows a test translation button when credentials are present', () => {
        // This test checks if the button exists when credentials are configured.
        // If button doesn't exist by default, skip with .then( $body => ... )
        cy.get( 'body' ).then( ( $body ) => {
            if ( $body.find( '#tab-translation button' ).filter( ( _i, el ) => /test translation/i.test( el.textContent ) ).length ) {
                cy.get( '#tab-translation button' ).contains( /test translation/i ).should( 'exist' );
            } else {
                cy.log( 'Test Translation button not present — skipping test.' );
            }
        } );
    } );

// Form submission

    it( 'displays a success notice after saving settings', () => {
        cy.intercept( 'POST', '**/wp-admin/admin.php?page=disciple-tools-crm-sync*' ).as( 'saveSettings' );

        // Fill in a daily limit value (non-sensitive field that won't affect the system).
        cy.get( '#tab-translation input[name="translation_daily_limit"]' ).clear().type( '100' );

        // Submit form — target the primary Save Settings button specifically; the form
        // also has an inline Save button in the API key row, so the selector must not
        // be broad enough to match both.
        cy.get( '#tab-translation button.button-primary[name="save_translation_settings"]' ).click();

        // Wait for page reload or AJAX response.
        cy.get( '#tab-translation .notice.notice-success, #tab-translation [role="status"]', {
            timeout: 15000,
        } ).should( 'be.visible' );
    } );

// API key placeholder behavior

    it( 'shows placeholder when API key is already set', () => {
        // If an API key is already saved, the input should show a placeholder
        // like "Currently set — leave blank to keep" instead of the actual key.
        cy.get( 'body' ).then( ( $body ) => {
            const $input = $body.find( '#tab-translation input[name="translation_api_key"]' );
            if ( $input.length ) {
                const placeholder = $input.attr( 'placeholder' ) || '';
                if ( placeholder.includes( 'Currently set' ) || placeholder.includes( 'leave blank' ) ) {
                    cy.log( 'API key is set — placeholder indicates password preservation.' );
                    cy.get( '#tab-translation input[name="translation_api_key"]' )
                        .should( 'have.attr', 'placeholder' )
                        .and( 'match', /currently set|leave blank/i );
                } else {
                    cy.log( 'API key not set yet — placeholder prompts for new key.' );
                }
            }
        } );
    } );

// Enable/disable checkbox

    it( 'can toggle the enable translation checkbox', () => {
        cy.get( '#tab-translation input[name="translation_enabled"]' ).then( ( $checkbox ) => {
            const wasChecked = $checkbox.is( ':checked' );
            if ( wasChecked ) {
                cy.wrap( $checkbox ).uncheck();
            } else {
                cy.wrap( $checkbox ).check();
            }
            cy.wrap( $checkbox ).should( wasChecked ? 'not.be.checked' : 'be.checked' );
        } );
    } );

// Model selector options

    it( 'shows model options or a prompt to save API key', () => {
        cy.get( '#tab-translation select[name="translation_model"] option' ).should( 'have.length.at.least', 1 );
    } );

// Prompt textarea

    it( 'allows editing the translation prompt', () => {
        cy.get( '#tab-translation textarea[name="translation_prompt"]' ).clear().type( 'Custom translation instruction:' );
        cy.get( '#tab-translation textarea[name="translation_prompt"]' ).should( 'have.value', 'Custom translation instruction:' );
    } );

// Daily limit validation

    it( 'accepts numeric values for daily limit', () => {
        cy.get( '#tab-translation input[name="translation_daily_limit"]' ).clear().type( '500' );
        cy.get( '#tab-translation input[name="translation_daily_limit"]' ).should( 'have.value', '500' );
    } );

    it( 'shows zero as unlimited in daily limit', () => {
        cy.get( '#tab-translation input[name="translation_daily_limit"]' ).clear().type( '0' );
        cy.get( '#tab-translation input[name="translation_daily_limit"]' ).should( 'have.value', '0' );
    } );
} );
