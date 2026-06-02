/* globals window, document, fetch */
/**
 * Tab Automations: interval/poll-time toggle and run-now button handlers.
 *
 * Depends on window.dtCrmSync being populated by wp_localize_script() before
 * this script runs (dt-crm-sync-admin-data handle, enqueued in head).
 */
( function() {
// Interval / poll-time toggle
    var interval = document.getElementById( 'dt_rio_filter_interval' );
    var timeRow  = document.getElementById( 'dt_rio_poll_time_row' );
    if ( interval && timeRow ) {
        /**
         * Show or hide the poll-time row based on the selected schedule interval.
         * The time-of-day picker is only relevant when the interval is 'daily'.
         */
        function toggle() {
            timeRow.style.display = interval.value === 'daily' ? '' : 'none';
        }
        toggle();
        interval.addEventListener( 'change', toggle );
    }

// Exclusive-group inputs: clear sibling fields in the same group when one is filled in.
// This enforces the tag/lifecycle mutual exclusivity without hardcoding field names.
    document.querySelectorAll( '[data-exclusive-group]' ).forEach( function( input ) {
        input.addEventListener( 'input', function() {
            if ( input.value.trim() === '' ) { return; }
            var group = input.getAttribute( 'data-exclusive-group' );
            document.querySelectorAll( '[data-exclusive-group="' + group + '"]' ).forEach( function( other ) {
                if ( other !== input ) { other.value = ''; }
            } );
        } );
    } );

// Run-now buttons
    var api   = window.dtCrmSync || {};
    var root  = api.apiRoot  || '';
    var nonce = api.nonce    || '';
    var i18n  = api.i18n    || {};

    if ( ! nonce ) {
        console.error( 'dt-crm-sync: window.dtCrmSync.nonce is missing — wp_localize_script may not have run. All run-now requests will be rejected.' );
        document.querySelectorAll( '.dt-rio-run-now' ).forEach( function( btn ) {
            var filterId = btn.getAttribute( 'data-filter-id' );
            var statusEl = document.getElementById( 'dt-rio-run-status-' + filterId );
            btn.disabled = true;
            if ( statusEl ) {
                statusEl.textContent = i18n.requestError || 'Authentication error';
                statusEl.classList.add( 'dt-crm-status--error' );
            }
        } );
    } else {
        document.querySelectorAll( '.dt-rio-run-now' ).forEach( function( btn ) {
            btn.addEventListener( 'click', function() {
                var filterId = btn.getAttribute( 'data-filter-id' );
                var statusEl = document.getElementById( 'dt-rio-run-status-' + filterId );
                btn.disabled = true;
                if ( statusEl ) {
                    statusEl.textContent = i18n.queueing || '';
                    statusEl.classList.remove( 'dt-crm-status--ok', 'dt-crm-status--error', 'dt-crm-status--neutral' );
                    statusEl.classList.add( 'dt-crm-status--neutral' );
                }
                fetch( root + '/saved-filters/' + filterId + '/run', {
                    method  : 'POST',
                    headers : { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
                } )
                .then( function( r ) {
                    if ( ! r.ok ) { throw new Error( r.statusText ); }
                    return r.json();
                } )
                .then( function( data ) {
                    var ok  = data && data.success;
                    var msg = ok
                        ? ( i18n.queued || '' )
                        : ( ( data && data.message ) || ( i18n.failed || '' ) );
                    if ( statusEl ) {
                        statusEl.textContent = msg;
                        statusEl.classList.remove( 'dt-crm-status--ok', 'dt-crm-status--error', 'dt-crm-status--neutral' );
                        statusEl.classList.add( ok ? 'dt-crm-status--ok' : 'dt-crm-status--error' );
                    }
                } )
                .catch( function() {
                    if ( statusEl ) {
                        statusEl.textContent = i18n.requestError || '';
                        statusEl.classList.remove( 'dt-crm-status--ok', 'dt-crm-status--error', 'dt-crm-status--neutral' );
                        statusEl.classList.add( 'dt-crm-status--error' );
                    }
                } )
                .finally( function() { btn.disabled = false; } );
            } );
        } );
    } // end nonce guard

// Create-filter form AJAX submission
    var createFormHidden = document.querySelector( '#tab-automations input[name="action"][value="save_filter"]' )
        || document.querySelector( 'input[name="action"][value="save_filter"]' );
    if ( createFormHidden ) {
        var createForm = createFormHidden.closest( 'form' );
        if ( createForm ) {
            createForm.addEventListener( 'submit', function( event ) {
                event.preventDefault();

                var nameInput     = createForm.querySelector( 'input[name="filter_name"]' );
                var intervalInput = createForm.querySelector( 'select[name="interval"]' );
                var pollTimeInput = createForm.querySelector( 'select[name="filter_poll_time"]' );

                var filterName  = nameInput     ? nameInput.value.trim()    : '';
                var filterIntvl = intervalInput ? intervalInput.value       : 'daily';
                var filterPoll  = pollTimeInput ? pollTimeInput.value       : '00:00';

                if ( ! filterName ) { return; } // HTML5 required validation handles this

                var filterParams = {};
                createForm.querySelectorAll( 'input[name^="filter_params_"]' ).forEach( function( inp ) {
                    var slug = inp.name.replace( 'filter_params_', '' );
                    filterParams[ slug ] = inp.value.trim();
                } );

                var skipExistingEl = createForm.querySelector( '#dt_rio_skip_existing' );
                var skipExisting   = skipExistingEl ? skipExistingEl.checked : true;

                var submitBtn = createForm.querySelector( '[type="submit"]' );
                if ( submitBtn ) { submitBtn.disabled = true; }

                fetch( root + '/saved-filters', {
                    method  : 'POST',
                    headers : { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
                    body    : JSON.stringify( {
                        name          : filterName,
                        interval      : filterIntvl,
                        poll_time     : filterPoll,
                        filter_params : filterParams,
                        skip_existing : skipExisting,
                    } ),
                } )
                .then( function( r ) {
                    if ( ! r.ok ) { throw new Error( r.statusText ); }
                    return r.json();
                } )
                .then( function() {
                    window.location.reload();
                } )
                .catch( function( err ) {
                    var existing = createForm.parentNode.querySelector( '.dt-crm-sync-create-error' );
                    if ( existing ) { existing.remove(); }
                    var notice    = document.createElement( 'div' );
                    notice.className = 'notice notice-error inline dt-crm-sync-create-error';
                    notice.innerHTML = '<p>' + ( err.message || ( i18n.failed || 'Failed' ) ) + '</p>';
                    createForm.insertAdjacentElement( 'beforebegin', notice );
                    if ( submitBtn ) { submitBtn.disabled = false; }
                } );
            } );
        }
    }

// Delete-filter confirmation and AJAX submission
    document.querySelectorAll( '.dt-crm-sync-delete-form' ).forEach( function( form ) {
        form.addEventListener( 'submit', function( event ) {
            event.preventDefault();
            var message = i18n.deleteFilterConfirm || 'Delete this filter? This will stop all scheduled imports for it.';
            if ( ! window.confirm( message ) ) { return; }

            var filterIdInput = form.querySelector( 'input[name="filter_id"]' );
            var filterId      = filterIdInput ? filterIdInput.value : '';
            if ( ! filterId || ! nonce ) { return; }

            fetch( root + '/saved-filters/' + filterId, {
                method  : 'DELETE',
                headers : { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
            } )
            .then( function( r ) {
                if ( ! r.ok ) { throw new Error( r.statusText ); }
                window.location.reload();
            } )
            .catch( function() {
                var notice = document.createElement( 'div' );
                notice.className = 'notice notice-error inline';
                notice.style.cssText = 'margin: 4px 0; padding: 6px 10px;';
                notice.innerHTML = '<p>' + ( i18n.deleteFilterError || 'Delete failed. Check the browser console and try reloading.' ) + '</p>';
                form.insertAdjacentElement( 'afterend', notice );
            } );
        } );
    } );

// Purge-all button
    var btnPurge = document.getElementById( 'dt-crm-sync-purge-all' );
    if ( btnPurge ) {
        btnPurge.addEventListener( 'click', function() {
            var message = i18n.purgeAllConfirm || 'Remove all scheduled plugin cron events? This will stop all scheduled polling.';
            if ( ! window.confirm( message ) ) { return; }
            btnPurge.disabled = true;
            var statusEl = document.getElementById( 'dt-crm-sync-purge-status' );
            if ( statusEl ) { statusEl.textContent = i18n.purging || 'Purging\u2026'; }
            fetch( root + '/saved-filters/purge', {
                method  : 'DELETE',
                headers : { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
            } )
            .then( function( r ) { return r.json(); } )
            .then( function() { window.location.reload(); } )
            .catch( function() {
                if ( statusEl ) { statusEl.textContent = i18n.requestError || 'Request error'; }
                btnPurge.disabled = false;
            } );
        } );
    }
} )();
