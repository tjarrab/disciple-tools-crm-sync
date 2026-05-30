/* global ajaxurl, dtCrmSyncNotice */
jQuery( function ( $ ) {
    $( document ).on( 'click', '.notice-disciple-tools-crm-sync .notice-dismiss', function () {
        $.ajax( ajaxurl, {
            type: 'POST',
            data: {
                action: 'dismissed_notice_handler',
                type: 'disciple-tools-crm-sync',
                security: dtCrmSyncNotice.nonce,
            },
        } );
    } );
} );
