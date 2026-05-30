import { useState, useEffect, useCallback } from '@wordpress/element';
import { Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { apiFetch } from '../utils/apiFetch';
import FilterBar from './FilterBar';
import ContactTable from './ContactTable';
import Pagination from './Pagination';
import ImportButton from './ImportButton';
import SessionExpiredBanner from './SessionExpiredBanner';

/**
 * Main application component for the Importer.
 *
 * API contract:
 *   POST /disciple-tools-crm-sync/v1/contacts
 *     body:  { search?, tag? }        — timezone auto-added server-side
 *     query: ?cursorId=<string>        — omit on first page
 *     response: { data: Contact[], cursor: { next: string|null } }
 *
 *   POST /disciple-tools-crm-sync/v1/import
 *     body:  { ids: number[] }
 *     response: { status: 'queued', batches: number }
 *
 * window.dtCrmSync is injected by wp_localize_script:
 *   { apiRoot, nonce, siteUrl, pluginUrl }
 */
export default function App() {
    const [ contacts, setContacts ] = useState( [] );
    const [ nextCursorId, setNextCursorId ] = useState( null );
    const [ filters, setFilters ] = useState( { search: '', tag: '' } );
    const [ selectedIds, setSelectedIds ] = useState( new Set() );
    const [ isLoading, setIsLoading ] = useState( false );
    const [ error, setError ] = useState( null );
    const [ sessionExpired, setSessionExpired ] = useState( false );

    /**
     * Fetch a page of contacts from the /contacts endpoint.
     *
     * Passing cursor=null triggers a fresh search (replaces the contact list
     * and clears the selection). Passing a cursor string appends to the list.
     *
     * Empty-string filter keys are stripped before sending so the PHP
     * doesn't forward blank strings to Respond.io.
     *
     * @param {Object}      filterBody  { search?: string, tag?: string }
     * @param {string|null} cursor      Cursor for the next page, or null.
     */
    const fetchContacts = useCallback( async ( filterBody, cursor ) => {
        setIsLoading( true );
        setError( null );

        // Build URL — apiRoot has no trailing slash.
        let url = window.dtCrmSync?.apiRoot + '/contacts';
        if ( cursor ) {
            url += '?cursorId=' + encodeURIComponent( cursor );
        }

        // Strip empty-string values so Respond.io doesn't receive blank filters.
        const cleanBody = Object.fromEntries(
            Object.entries( filterBody ).filter( ( [ , v ] ) => v !== '' )
        );

        try {
            const data = await apiFetch( url, {
                method: 'POST',
                body: JSON.stringify( { filter_params: cleanBody } ),
            } );

            const newContacts = Array.isArray( data.data ) ? data.data : [];

            if ( cursor ) {
                // Append next page — preserve existing selection.
                setContacts( ( prev ) => [ ...prev, ...newContacts ] );
            } else {
                // Fresh search — replace the list and clear selection.
                setContacts( newContacts );
                setSelectedIds( new Set() );
            }

            setNextCursorId( data.cursor?.next ?? null );
        } catch ( err ) {
            if ( err.message === 'SESSION_EXPIRED' ) {
                setSessionExpired( true );
            } else {
                setError( err.message );
            }
        } finally {
            setIsLoading( false );
        }
        // Empty dep array: all mutable data arrives as explicit arguments.
        // Adding reactive state here creates a re-render loop — keep deps empty.
    }, [] );

    const handleSessionExpired = useCallback( () => setSessionExpired( true ), [] );

    useEffect( () => {
        fetchContacts( {}, null );
    }, [ fetchContacts ] );

    function handleFilterSubmit( newFilters ) {
        setFilters( newFilters );
        fetchContacts( newFilters, null );
    }

    function handleLoadMore() {
        fetchContacts( filters, nextCursorId );
    }

    // Guard against missing wp_localize_script data (e.g. script dequeued or stripped by a caching plugin).
    if ( ! window.dtCrmSync?.apiRoot ) {
        return (
            <div className="notice notice-error inline">
                <p>{ __( 'CRM Sync configuration is unavailable. Please reload the page.', 'disciple-tools-crm-sync' ) }</p>
            </div>
        );
    }

    // Once a session expiry is detected, render only the banner — no further
    // API calls are possible until the page is reloaded.
    if ( sessionExpired ) {
        return <SessionExpiredBanner />;
    }

    const showInitialSpinner = isLoading && contacts.length === 0;

    return (
        <div className="dt-crm-sync-importer">
            <FilterBar
                onSubmit={ handleFilterSubmit }
                isLoading={ isLoading }
            />

            { error && (
                <div className="notice notice-error inline" style={ { marginBottom: '0.75rem' } }>
                    <p>{ error }</p>
                </div>
            ) }

            { showInitialSpinner ? (
                <div style={ { padding: '1rem 0' } }>
                    <Spinner />
                    <span className="screen-reader-text">
                        { __( 'Loading contacts\u2026', 'disciple-tools-crm-sync' ) }
                    </span>
                </div>
            ) : (
                <ContactTable
                    contacts={ contacts }
                    selectedIds={ selectedIds }
                    onSelectionChange={ setSelectedIds }
                />
            ) }

            <Pagination
                nextCursorId={ nextCursorId }
                isLoading={ isLoading && contacts.length > 0 }
                onLoadMore={ handleLoadMore }
            />

            <ImportButton
                selectedIds={ selectedIds }
                isLoading={ isLoading }
                onSessionExpired={ handleSessionExpired }
            />
        </div>
    );
}
