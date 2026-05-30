// Entry point: mounts the Importer SPA into the div rendered by Disciple_Tools_CRM_Sync_Tab_Importer.
import { createRoot } from '@wordpress/element';
import App from './components/App';

const container = document.getElementById( 'dt-crm-sync-importer-root' );
if ( container ) {
    createRoot( container ).render( <App /> );
}
