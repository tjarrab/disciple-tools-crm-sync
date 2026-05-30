import { Button, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import PropTypes from 'prop-types';

/**
 * "Load More" button for cursor-based pagination. Renders a spinner alongside
 * the disabled button during an in-flight request.
 *
 * @param {Object}      props
 * @param {string|null} props.nextCursorId  Cursor from the last API response.
 * @param {boolean}     props.isLoading     True while a next-page request is in-flight.
 * @param {Function}    props.onLoadMore    Callback to fetch the next page.
 */
export default function Pagination( { nextCursorId, isLoading, onLoadMore } ) {
    if ( ! nextCursorId && ! isLoading ) {
        return null;
    }

    return (
        <div style={ { marginTop: '1rem', display: 'flex', alignItems: 'center', gap: '0.5rem' } }>
            <Button
                variant="secondary"
                disabled={ ! nextCursorId || isLoading }
                onClick={ onLoadMore }
                isBusy={ isLoading }
            >
                { isLoading
                    ? __( 'Loading\u2026', 'disciple-tools-crm-sync' )
                    : __( 'Load More', 'disciple-tools-crm-sync' )
                }
            </Button>
            { isLoading && <Spinner /> }
        </div>
    );
}

Pagination.propTypes = {
    nextCursorId: PropTypes.string,
    isLoading: PropTypes.bool.isRequired,
    onLoadMore: PropTypes.func.isRequired,
};
