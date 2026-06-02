import { useState, Fragment } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import PropTypes from 'prop-types';

// Connector-defined filter fields from wp_localize_script.
const filterFields = ( window.dtCrmSync && window.dtCrmSync.filterFields ) || [];

/**
 * Collapse filter fields into render items.
 *
 * Fields that share an exclusive_group are grouped into a single item so the UI
 * can render an "or" separator between them. Standalone fields pass straight through.
 *
 * @param {Array} fields  Raw field definitions from the connector.
 * @returns {Array}       Ordered render items, each with type 'standalone' or 'group'.
 */
function buildRenderItems( fields ) {
    const items      = [];
    const seenGroups = {};

    fields.forEach( ( field ) => {
        const group = field.exclusive_group;
        if ( group ) {
            if ( seenGroups[ group ] === undefined ) {
                seenGroups[ group ] = items.length;
                items.push( {
                    type:       'group',
                    group,
                    groupLabel: field.group_label || group,
                    fields:     [],
                } );
            }
            items[ seenGroups[ group ] ].fields.push( field );
        } else {
            items.push( { type: 'standalone', field } );
        }
    } );

    return items;
}

const renderItems = buildRenderItems( filterFields );

/**
 * FilterBar
 *
 * Renders filter inputs dynamically from the active connector's filter fields.
 * Calls props.onSubmit with a generic filter_params object keyed by field slug.
 *
 * @param {Object}   props
 * @param {Function} props.onSubmit  Callback receiving filter_params object.
 * @param {boolean}  props.isLoading Disables the Search button while loading.
 */
export default function FilterBar( { onSubmit, isLoading } ) {
    // Initialise state as an empty object; keys are field slugs.
    const [ params, setParams ] = useState( () => {
        const initial = {};
        filterFields.forEach( ( f ) => { initial[ f.slug ] = ''; } );
        return initial;
    } );

    function handleChange( slug, value ) {
        const field = filterFields.find( ( f ) => f.slug === slug );
        const group = field && field.exclusive_group;

        setParams( ( prev ) => {
            const next = { ...prev, [ slug ]: value };
            // If this field belongs to an exclusive group and has a non-empty value,
            // clear all other fields in the same group.
            if ( group && value.trim() !== '' ) {
                filterFields.forEach( ( f ) => {
                    if ( f.slug !== slug && f.exclusive_group === group ) {
                        next[ f.slug ] = '';
                    }
                } );
            }
            return next;
        } );
    }

    function handleSubmit( e ) {
        e.preventDefault();
        // Trim all values before sending.
        const trimmed = {};
        Object.entries( params ).forEach( ( [ k, v ] ) => { trimmed[ k ] = v.trim(); } );
        onSubmit( trimmed );
    }

    return (
        <form
            data-testid="filter-bar"
            onSubmit={ handleSubmit }
            style={ { marginBottom: '1rem', display: 'flex', gap: '0.5rem', flexWrap: 'wrap', alignItems: 'flex-end' } }
        >
            { renderItems.map( ( item ) => {
                if ( item.type === 'standalone' ) {
                    const field = item.field;
                    return (
                        <div key={ field.slug }>
                            <label htmlFor={ `crm-filter-${ field.slug }` } className="screen-reader-text">
                                { field.label }
                            </label>
                            <input
                                id={ `crm-filter-${ field.slug }` }
                                type="text"
                                className="regular-text"
                                placeholder={ field.placeholder || field.label }
                                value={ params[ field.slug ] || '' }
                                onChange={ ( e ) => handleChange( field.slug, e.target.value ) }
                            />
                        </div>
                    );
                }

                // Grouped fields — show visible labels and "or" between each input.
                return (
                    <div key={ item.group } className="dt-crm-filter-group">
                        { item.fields.map( ( field, idx ) => (
                            <Fragment key={ field.slug }>
                                <div className="dt-crm-filter-option">
                                    <label htmlFor={ `crm-filter-${ field.slug }` }>
                                        { field.label }
                                    </label>
                                    <input
                                        id={ `crm-filter-${ field.slug }` }
                                        type="text"
                                        className="regular-text"
                                        placeholder={ field.placeholder || '' }
                                        value={ params[ field.slug ] || '' }
                                        onChange={ ( e ) => handleChange( field.slug, e.target.value ) }
                                    />
                                </div>
                                { idx < item.fields.length - 1 && (
                                    <span className="dt-crm-or-separator" aria-hidden="true">
                                        { __( 'or', 'disciple-tools-crm-sync' ) }
                                    </span>
                                ) }
                            </Fragment>
                        ) ) }
                    </div>
                );
            } ) }

            <Button
                variant="primary"
                type="submit"
                disabled={ isLoading }
            >
                { __( 'Search', 'disciple-tools-crm-sync' ) }
            </Button>
        </form>
    );
}

FilterBar.propTypes = {
    onSubmit:  PropTypes.func.isRequired,
    isLoading: PropTypes.bool.isRequired,
};
