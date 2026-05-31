import { createRoot } from '@wordpress/element';
import '../../css/tailwind.css';
import { App } from '../app/App';

// Public/member viewer app. Compiled to build/viewer.js and mounted on the
// [clubcompetitie] shortcode's <div id="scs-app">.
const mount = document.getElementById( 'scs-app' );
if ( mount ) {
	createRoot( mount ).render( <App /> );
}
