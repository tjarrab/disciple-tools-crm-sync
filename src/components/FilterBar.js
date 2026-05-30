import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import PropTypes from 'prop-types';

// Connector-defined filter fields from wp_localize_script.
const filterFields = ( window.dtCrmSync && window.dtCrmSync.filterFields ) || [];

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
        setParams( ( prev ) => ( { ...prev, [ slug ]: value } ) );
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
            { filterFields.map( ( field ) => (
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
            ) ) }

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
