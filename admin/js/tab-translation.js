/* globals window, document, fetch */
/**
 * Tab Translation: Refresh Models and Test Translation button handlers.
 *
 * Depends on window.dtCrmSync being populated by wp_localize_script() before
 * this script runs (dt-crm-sync-admin-data handle, enqueued in head).
 */
( function() {
    var api   = window.dtCrmSync || {};
    var root  = api.apiRoot  || '';
    var nonce = api.nonce    || '';
    var i18n  = api.i18n    || {};

    function setStatus( elId, msg, ok ) {
        var el = document.getElementById( elId );
        if ( ! el ) { return; }
        el.textContent = msg;
        el.classList.remove( 'dt-crm-status--ok', 'dt-crm-status--error', 'dt-crm-status--neutral' );
        el.classList.add( ok ? 'dt-crm-status--ok' : 'dt-crm-status--error' );
    }

    if ( ! nonce ) {
        return;
    }

    // Refresh Models button: clears the server-side transient then reloads the page
    // so the model <select> is repopulated with the fresh list.
    var btnRefresh = document.getElementById( 'dt-translation-refresh-models' );
    if ( btnRefresh ) {
        btnRefresh.addEventListener( 'click', function() {
            btnRefresh.disabled = true;
            setStatus( 'dt-translation-models-result', i18n.refreshing || 'Refreshing\u2026', true );
            fetch( root + '/translation/models-cache', {
                method  : 'DELETE',
                headers : { 'X-WP-Nonce': nonce },
            } )
            .then( function( r ) {
                return r.json().catch( function() {
                    throw new Error( r.status + ' ' + r.statusText );
                } );
            } )
            .then( function( data ) {
                if ( data && data.success ) {
                    setStatus( 'dt-translation-models-result', i18n.doneReloading || 'Done \u2014 reloading\u2026', true );
                    window.location.reload();
                } else {
                    setStatus( 'dt-translation-models-result', i18n.refreshFailed || 'Refresh failed', false );
                    btnRefresh.disabled = false;
                }
            } )
            .catch( function() {
                setStatus( 'dt-translation-models-result', i18n.requestError || 'Request error', false );
                btnRefresh.disabled = false;
            } );
        } );
    }

    // Test Translation button: sends a fixed Spanish phrase to the server-side
    // test endpoint and shows the translated result (or an error message).
    var btnTest = document.getElementById( 'dt-translation-test' );
    if ( btnTest ) {
        btnTest.addEventListener( 'click', function() {
            btnTest.disabled = true;
            setStatus( 'dt-translation-test-result', i18n.testing || 'Testing\u2026', true );
            fetch( root + '/translation/test', {
                method  : 'POST',
                headers : { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
            } )
            .then( function( r ) {
                return r.json().catch( function() {
                    throw new Error( r.status + ' ' + r.statusText );
                } );
            } )
            .then( function( data ) {
                if ( data && data.success ) {
                    setStatus( 'dt-translation-test-result', '\u201c' + data.translation + '\u201d', true );
                } else {
                    setStatus( 'dt-translation-test-result', ( data && data.message ) || ( i18n.failed || 'Failed' ), false );
                }
                btnTest.disabled = false;
            } )
            .catch( function() {
                setStatus( 'dt-translation-test-result', i18n.requestError || 'Request error', false );
                btnTest.disabled = false;
            } );
        } );
    }

} )();
