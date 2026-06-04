/* globals window, document, fetch */
/**
 * Tab Config: Test Connection and Refresh Schema button handlers.
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
        el.classList.remove( 'dt-crm-status--ok', 'dt-crm-status--error' );
        el.classList.add( ok ? 'dt-crm-status--ok' : 'dt-crm-status--error' );
    }

    if ( ! nonce ) {
        console.error( 'dt-crm-sync: window.dtCrmSync.nonce is missing — wp_localize_script may not have run. All config requests will be rejected.' );
        var btnTestDisable    = document.getElementById( 'dt-rio-test-connection' );
        var btnRefreshDisable = document.getElementById( 'dt-rio-refresh-schema' );
        if ( btnTestDisable )    { btnTestDisable.disabled   = true; setStatus( 'dt-rio-test-result',   i18n.requestError || 'Authentication error', false ); }
        if ( btnRefreshDisable ) { btnRefreshDisable.disabled = true; setStatus( 'dt-rio-schema-result', i18n.requestError || 'Authentication error', false ); }
        return;
    }

    var btnTest = document.getElementById( 'dt-rio-test-connection' );
    var testLog = document.getElementById( 'dt-rio-test-log' );

    function showTestLog( text ) {
        if ( ! testLog ) { return; }
        // textContent is used intentionally — never innerHTML — so that
        // API-provided values (data.debug.data, data.message, etc.) cannot
        // inject HTML or execute scripts in the admin context.
        testLog.textContent = text;
        testLog.style.display = 'block';
    }

    function hideTestLog() {
        if ( ! testLog ) { return; }
        testLog.textContent = '';
        testLog.style.display = 'none';
    }

    var schemaLog = document.getElementById( 'dt-rio-schema-log' );

    function showSchemaLog( text ) {
        if ( ! schemaLog ) { return; }
        schemaLog.textContent = text;
        schemaLog.style.display = 'block';
    }

    function hideSchemaLog() {
        if ( ! schemaLog ) { return; }
        schemaLog.textContent = '';
        schemaLog.style.display = 'none';
    }

    if ( btnTest ) {
        btnTest.addEventListener( 'click', function() {
            btnTest.disabled = true;
            hideTestLog();
            setStatus( 'dt-rio-test-result', i18n.testing || '', true );
            fetch( root + '/test-connection', {
                method  : 'POST',
                headers : { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
            } )
            .then( function( r ) {
                return r.json().catch( function() {
                    throw new Error( r.status + ' ' + r.statusText );
                } );
            } )
            .then( function( data ) {
                var ok  = data && data.success;
                var msg = ok
                    ? ( i18n.connectionSuccessful || '' )
                    : ( ( data && data.message ) || ( i18n.connectionFailed || '' ) );
                setStatus( 'dt-rio-test-result', msg, ok );
                if ( ! ok ) {
                    var logLines = [ msg ];
                    if ( data && data.debug ) {
                        if ( data.debug.code ) { logLines.push( 'Error code: ' + data.debug.code ); }
                        if ( data.debug.data ) { logLines.push( 'Details: ' + JSON.stringify( data.debug.data, null, 2 ) ); }
                    }
                    showTestLog( logLines.join( '\n' ) );
                } else {
                    hideTestLog();
                }
            } )
            .catch( function( err ) {
                var errMsg = ( err && err.message ) ? err.message : String( err );
                console.error( 'dt-crm-sync: test connection request failed:', err );
                setStatus( 'dt-rio-test-result', ( i18n.requestError || '' ) + ': ' + errMsg, false );
                showTestLog( ( err && err.stack ) ? err.stack : errMsg );
            } )
            .finally( function() { btnTest.disabled = false; } );
        } );
    }

    var btnRefresh = document.getElementById( 'dt-rio-refresh-schema' );
    if ( btnRefresh ) {
        btnRefresh.addEventListener( 'click', function() {
            btnRefresh.disabled = true;
            hideSchemaLog();
            setStatus( 'dt-rio-schema-result', i18n.refreshing || '', true );
            fetch( root + '/schema?refresh=1', {
                headers : { 'X-WP-Nonce': nonce },
            } )
            .then( function( r ) {
                return r.json().catch( function() {
                    throw new Error( r.status + ' ' + r.statusText );
                } );
            } )
            .then( function( data ) {
                if ( data && data.success ) {
                    hideSchemaLog();
                    setStatus( 'dt-rio-schema-result', i18n.doneReloading || '', true );
                    window.location.reload();
                } else {
                    var msg = ( data && data.message ) || ( i18n.refreshFailed || '' );
                    setStatus( 'dt-rio-schema-result', msg, false );
                    btnRefresh.disabled = false;
                }
            } )
            .catch( function( err ) {
                var errMsg = ( err && err.message ) ? err.message : String( err );
                console.error( 'dt-crm-sync: schema refresh failed:', err );
                setStatus( 'dt-rio-schema-result', ( i18n.requestError || '' ) + ': ' + errMsg, false );
                showSchemaLog( ( err && err.stack ) ? err.stack : errMsg );
                btnRefresh.disabled = false;
            } );
        } );
    }
} )();
