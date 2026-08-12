import { bootstrap } from '../bootstrap';

/**
 * The running version, bottom left.
 *
 * There to answer "is my change live yet?" from the page instead of over SSH —
 * the deploy is a git pull, so nothing else on screen says which build served
 * it. Renders nothing when the version is empty, which is the case outside
 * WordPress, where there is no plugin to have one.
 */
export function Footer() {
	if ( ! bootstrap.version ) {
		return null;
	}

	// px-7 to sit under the page content, which Page.jsx indents by the same
	// amount — the nav bars use a narrower gutter.
	return (
		<footer className="mx-auto w-full max-w-page px-7 pb-8 print:hidden">
			<span className="num font-mono text-xs text-muted">
				{ bootstrap.version }
			</span>
		</footer>
	);
}
