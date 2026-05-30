import { __ } from '@wordpress/i18n';

/**
 * SessionExpiredBanner
 *
 * Rendered when the WP REST nonce has expired (HTTP 401 received from any
 * API call). There is no dismiss button — the user must reload the page to
 * obtain a fresh nonce.
 */
export default function SessionExpiredBanner() {
    return (
        <div className="notice notice-error" data-testid="session-expired-banner">
            <p>
                <strong>
                    { __( 'Session Expired', 'disciple-tools-crm-sync' ) }
                </strong>
                { ' — ' }
                { __( 'Your session has expired. Please reload the page to continue.', 'disciple-tools-crm-sync' ) }
            </p>
        </div>
    );
}
