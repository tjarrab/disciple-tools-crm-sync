/* global jQuery, ajaxurl, dtCrmSyncNotice */
jQuery( function ( $ ) {
    $( document ).on( 'click', '.notice-disciple-tools-crm-sync .notice-dismiss', function () {
        var $notice = $( this ).closest( '.notice-disciple-tools-crm-sync' );

        $.ajax( ajaxurl, {
            type: 'POST',
            data: {
                action: 'dismissed_notice_handler',
                type: 'disciple-tools-crm-sync',
                security: dtCrmSyncNotice.nonce,
            },
        } ).fail( function () {
            var msg = ( dtCrmSyncNotice.dismissError ) || 'Could not save the notice dismissal \u2014 it will reappear on your next visit.';
            var $error = $( '<div class="notice notice-warning inline"><p></p></div>' );
            $error.find( 'p' ).text( msg );
            $notice.after( $error );
        } );
    } );
} );
