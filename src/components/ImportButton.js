import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import PropTypes from 'prop-types';
import { apiFetch } from '../utils/apiFetch';

/**
 * ImportButton
 *
 * Dispatches a batch import for the currently selected contacts by POSTing
 * their IDs to the /import REST endpoint. On success, displays a confirmation
 * banner with a direct link to the Sync Logs tab (Tab 4).
 *
 * @param {Object}   props
 * @param {Set}      props.selectedIds Set of Respond.io contact IDs (integers) to import.
 * @param {boolean}  props.isLoading True while the contact list is being fetched.
 * @param {Function} props.onSessionExpired Called when the API returns HTTP 401.
 */
export default function ImportButton( { selectedIds, isLoading, onSessionExpired } ) {
    const [ dispatching, setDispatching ] = useState( false );
    const [ importQueued, setImportQueued ] = useState( false );
    const [ error, setError ] = useState( null );

    const disabled = selectedIds.size === 0 || isLoading || dispatching;

    const count    = selectedIds.size;
    const logsUrl  = window.dtCrmSync?.logsUrl ?? '#';

    async function handleImport() {
        setDispatching( true );
        setError( null );

        const apiRoot = window.dtCrmSync?.apiRoot;
        if ( ! apiRoot ) {
            setError( __( 'Plugin configuration is unavailable. Please reload the page.', 'disciple-tools-crm-sync' ) );
            setDispatching( false );
            return;
        }

        try {
            await apiFetch( apiRoot + '/import', {
                method: 'POST',
                body: JSON.stringify( { ids: [ ...selectedIds ] } ),
            } );
            setImportQueued( true );
        } catch ( err ) {
            if ( err.message === 'SESSION_EXPIRED' ) {
                onSessionExpired();
            } else {
                setError( err.message );
            }
        } finally {
            setDispatching( false );
        }
    }

    return (
        <div style={ { marginTop: '1.25rem' } }>
            { importQueued && (
                <div className="notice notice-success inline" style={ { marginBottom: '0.75rem' } }>
                    <p>
                        { __( 'Import queued successfully.', 'disciple-tools-crm-sync' ) }
                        { ' ' }
                        <a href={ logsUrl }>
                            { __( 'View Sync Logs \u2192', 'disciple-tools-crm-sync' ) }
                        </a>
                    </p>
                </div>
            ) }

            { error && (
                <div className="notice notice-error inline" style={ { marginBottom: '0.75rem' } }>
                    <p>{ error }</p>
                </div>
            ) }

            <Button
                variant="primary"
                onClick={ handleImport }
                disabled={ disabled }
                isBusy={ dispatching }
            >
                { dispatching
                    ? __( 'Queuing Import\u2026', 'disciple-tools-crm-sync' )
                    : count > 0
                        ? __( 'Import Selected', 'disciple-tools-crm-sync' ) + ` (${ count })`
                        : __( 'Import Selected', 'disciple-tools-crm-sync' )
                }
            </Button>
        </div>
    );
}

ImportButton.propTypes = {
    selectedIds: PropTypes.instanceOf( Set ).isRequired,
    isLoading: PropTypes.bool.isRequired,
    onSessionExpired: PropTypes.func.isRequired,
};
