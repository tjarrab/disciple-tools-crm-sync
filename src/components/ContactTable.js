import { useRef, useEffect, useMemo } from '@wordpress/element';
import { CheckboxControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import PropTypes from 'prop-types';

/**
 * ContactTable
 *
 * Renders a WP-admin-styled table of Respond.io contacts with per-row
 * checkboxes and a select-all header checkbox (supports indeterminate state).
 *
 * Tag items from the Respond.io API may be plain strings or objects with a
 * `name` property — both shapes are handled defensively.
 *
 * @param {Object}   props
 * @param {Array}    props.contacts          Array of contact objects from the API.
 * @param {Set}      props.selectedIds       Set of currently selected contact IDs.
 * @param {Function} props.onSelectionChange Callback receiving the updated Set.
 */
export default function ContactTable( { contacts, selectedIds, onSelectionChange } ) {
    const selectAllRef = useRef( null );

    const allSelected  = useMemo(
        () => contacts.length > 0 && contacts.every( ( c ) => selectedIds.has( c.id ) ),
        [ contacts, selectedIds ]
    );
    const someSelected = useMemo(
        () => contacts.some( ( c ) => selectedIds.has( c.id ) ),
        [ contacts, selectedIds ]
    );

    useEffect( () => {
        if ( ! selectAllRef.current ) {
            return;
        }
        selectAllRef.current.checked       = allSelected;
        selectAllRef.current.indeterminate = someSelected && ! allSelected;
    }, [ allSelected, someSelected ] );

    function handleSelectAll( e ) {
        if ( e.target.checked ) {
            onSelectionChange( new Set( [ ...selectedIds, ...contacts.map( ( c ) => c.id ) ] ) );
        } else {
            const contactIdSet = new Set( contacts.map( ( c ) => c.id ) );
            onSelectionChange( new Set( [ ...selectedIds ].filter( ( id ) => ! contactIdSet.has( id ) ) ) );
        }
    }

    function handleRowToggle( contactId, checked ) {
        const next = new Set( selectedIds );
        if ( checked ) {
            next.add( contactId );
        } else {
            next.delete( contactId );
        }
        onSelectionChange( next );
    }

    /**
     * Format the tags array as a comma-separated string.
     * Handles both string[] and {name: string}[] tag shapes.
     */
    function formatTags( tags ) {
        if ( ! Array.isArray( tags ) || tags.length === 0 ) {
            return '\u2014';
        }
        return tags
            .map( ( t ) => ( t && typeof t === 'object' ? ( t.name ?? '' ) : String( t ) ) )
            .filter( Boolean )
            .join( ', ' );
    }

    if ( contacts.length === 0 ) {
        return (
            <p className="description">
                { __( 'No contacts found. Try adjusting your search filters.', 'disciple-tools-crm-sync' ) }
            </p>
        );
    }

    return (
        <table className="wp-list-table widefat fixed striped" style={ { marginTop: '0.5rem' } }>
            <thead>
                <tr>
                    <th scope="col" style={ { width: '2.5rem' } }>
                        <input
                            ref={ selectAllRef }
                            type="checkbox"
                            aria-label={ __( 'Select all contacts on this page', 'disciple-tools-crm-sync' ) }
                            onChange={ handleSelectAll }
                        />
                    </th>
                    <th scope="col">{ __( 'Name', 'disciple-tools-crm-sync' ) }</th>
                    <th scope="col">{ __( 'Phone', 'disciple-tools-crm-sync' ) }</th>
                    <th scope="col">{ __( 'Email', 'disciple-tools-crm-sync' ) }</th>
                    <th scope="col">{ __( 'Tags', 'disciple-tools-crm-sync' ) }</th>
                    <th scope="col">{ __( 'Lifecycle', 'disciple-tools-crm-sync' ) }</th>
                </tr>
            </thead>
            <tbody>
                { contacts.map( ( contact ) => {
                    const fullName = [ contact.firstName, contact.lastName ]
                        .filter( Boolean )
                        .join( ' ' ) || '\u2014';

                    return (
                        <tr key={ contact.id }>
                            <td>
                                <CheckboxControl
                                    checked={ selectedIds.has( contact.id ) }
                                    onChange={ ( checked ) => handleRowToggle( contact.id, checked ) }
                                    label=""
                                    aria-label={
                                        __( 'Select', 'disciple-tools-crm-sync' ) + ' ' + fullName
                                    }
                                    __nextHasNoMarginBottom
                                />
                            </td>
                            <td>{ fullName }</td>
                            <td>{ contact.phone || '\u2014' }</td>
                            <td>{ contact.email || '\u2014' }</td>
                            <td>{ formatTags( contact.tags ) }</td>
                            <td>{ contact.lifecycle || '\u2014' }</td>
                        </tr>
                    );
                } ) }
            </tbody>
        </table>
    );
}

ContactTable.propTypes = {
    contacts:          PropTypes.array.isRequired,
    selectedIds:       PropTypes.instanceOf( Set ).isRequired,
    onSelectionChange: PropTypes.func.isRequired,
};
