// Centered content column matching the hi-fi `.page` width/padding.
export function Page( { children } ) {
	return (
		<main className="mx-auto w-full max-w-page px-7 pb-20 pt-8">
			{ children }
		</main>
	);
}

// Foundation placeholder for a view whose real implementation is a later pass.
// Renders the view title + a short note on what it will show and what it
// depends on, so the scaffold is navigable and self-documenting.
export function Placeholder( { title, children } ) {
	return (
		<Page>
			<h1 className="mb-2 font-serif text-[38px] leading-[1.1]">
				{ title }
			</h1>
			<div className="max-w-2xl rounded border border-dashed border-rule bg-surface p-6 text-ink-3">
				{ children }
			</div>
		</Page>
	);
}
