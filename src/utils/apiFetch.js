/**
 * Shared fetch utility for the Respond.io Importer SPA.
 *
 * Injects the WP REST nonce and content-type headers, detects session expiry
 * (HTTP 401), and throws typed errors for all non-OK responses.
 *
 * @param {string} url        Full URL to fetch.
 * @param {Object} options    Standard fetch options (method, body, headers, etc.).
 * @param {number} [timeout]  Milliseconds before the request is aborted. Default 30 000.
 *                            Pass 0 to disable the timeout.
 * @returns {Promise<any>} Parsed JSON response body.
 * @throws {Error} message === 'SESSION_EXPIRED'  on 401.
 * @throws {Error} message === 'REQUEST_TIMEOUT'  when the timeout fires.
 * @throws {Error} message === 'HTTP N'           on other non-OK responses.
 */
export async function apiFetch( url, options = {}, timeout = 30000 ) {
    const method = ( options.method || 'GET' ).toUpperCase();
    const headers = {
        'X-WP-Nonce': window.dtCrmSync.nonce,
        ...( options.headers || {} ),
    };
    if ( method !== 'GET' ) {
        headers[ 'Content-Type' ] = 'application/json';
    }

    const controller = new AbortController();
    const timeoutId  = timeout > 0
        ? setTimeout( () => controller.abort(), timeout )
        : null;

    try {
        const response = await fetch( url, {
            ...options,
            headers,
            signal: controller.signal,
        } );

        if ( response.status === 401 ) {
            throw new Error( 'SESSION_EXPIRED' );
        }

        if ( ! response.ok ) {
            // Attempt to read the JSON error body so the actual server-side message
            // is surfaced in the UI instead of the opaque "HTTP 502" string.
            let message = `HTTP ${ response.status }`;
            try {
                const errorData = await response.json();
                if ( errorData && errorData.error ) {
                    message = errorData.error;
                }
            } catch ( _ ) {
                // Response wasn't JSON — keep the generic message.
            }
            throw new Error( message );
        }

        return response.json();
    } catch ( err ) {
        if ( err.name === 'AbortError' ) {
            throw new Error( 'REQUEST_TIMEOUT' );
        }
        throw err;
    } finally {
        if ( timeoutId !== null ) {
            clearTimeout( timeoutId );
        }
    }
}
