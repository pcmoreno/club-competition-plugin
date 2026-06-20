/**
 * Design tokens ported from the hi-fi design system
 * (../documents/santpoort-chess-competition/project/hifi-styles.css):
 * warm paper, deep ink, a single forest-green accent.
 *
 * The CSS custom properties live in css/tailwind.css (base layer); these
 * Tailwind aliases map utility classes (bg-paper, text-ink, font-serif, …)
 * onto them so components can be written with utilities while the palette
 * stays single-sourced in :root.
 */
module.exports = {
  content: [
    './js/**/*.{js,jsx}',
  ],
  // The viewer is embedded in WordPress hosts whose themes target generic
  // elements with selectors that tie/exceed our single-class utilities and load
  // after viewer.css (e.g. Hello Elementor's `.page-content a`, `button`,
  // `input`, table rules), so they win on specificity/source order. Marking our
  // utilities !important makes them beat those *normal* host rules regardless of
  // specificity or order. Verified the conflicting host rules are NOT themselves
  // !important (Elementor's !important only target .elementor-* classes we never
  // use), and the app uses no inline styles, so this has no downside here.
  // Pairs with the unlayered-utilities setup in css/tailwind.css.
  important: true,
  theme: {
    extend: {
      colors: {
        paper: 'var(--paper)',
        surface: {
          DEFAULT: 'var(--surface)',
          2: 'var(--surface-2)',
          3: 'var(--surface-3)',
        },
        ink: {
          DEFAULT: 'var(--ink)',
          2: 'var(--ink-2)',
          3: 'var(--ink-3)',
        },
        muted: 'var(--muted)',
        rule: {
          DEFAULT: 'var(--rule)',
          soft: 'var(--rule-soft)',
          strong: 'var(--rule-strong)',
        },
        accent: {
          DEFAULT: 'var(--accent)',
          2: 'var(--accent-2)',
          soft: 'var(--accent-soft)',
          rim: 'var(--accent-rim)',
        },
        win: 'var(--win)',
        loss: 'var(--loss)',
        draw: 'var(--draw)',
        'white-sq': 'var(--white-sq)',
        'black-sq': 'var(--black-sq)',
      },
      fontFamily: {
        sans: ['"IBM Plex Sans"', '-apple-system', 'BlinkMacSystemFont', '"Segoe UI"', 'system-ui', 'sans-serif'],
        serif: ['Newsreader', '"Source Serif 4"', 'Georgia', 'serif'],
        mono: ['"IBM Plex Mono"', 'ui-monospace', '"SF Mono"', 'monospace'],
      },
      borderRadius: {
        sm: 'var(--radius-sm)',
        DEFAULT: 'var(--radius)',
        lg: 'var(--radius-lg)',
      },
      boxShadow: {
        sm: 'var(--shadow-sm)',
        md: 'var(--shadow-md)',
      },
      maxWidth: {
        page: '1240px',
      },
    },
  },
  plugins: [
    require('@tailwindcss/forms'),
    // aspect-ratio and line-clamp are built into Tailwind core as of v4.
  ],
}
