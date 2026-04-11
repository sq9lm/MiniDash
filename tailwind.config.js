/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './*.php',
    './includes/*.php',
    './assets/js/*.js',
    './lang/*.json',
  ],
  safelist: [
    // Dynamic colors used in PHP/JS
    {pattern: /bg-(slate|blue|emerald|amber|red|rose|purple|indigo|cyan|orange|sky|green|teal)-(400|500|600|700|800|900)/},
    {pattern: /bg-(slate|blue|emerald|amber|red|rose|purple|indigo|cyan|orange|sky|green|teal)-(400|500|600|700|800|900)\/(5|10|20|30|40|50)/},
    {pattern: /text-(slate|blue|emerald|amber|red|rose|purple|indigo|cyan|orange|sky|green|teal)-(300|400|500|600|700)/},
    {pattern: /border-(slate|blue|emerald|amber|red|rose|purple|indigo|cyan|orange|sky|green|teal)-(400|500|600|700)\/(5|10|20|30)/},
    {pattern: /shadow-(slate|blue|emerald|amber|red|rose|purple|indigo|cyan|orange)-(500|600)\/(10|20|30)/},
    {pattern: /ring-(slate|blue|emerald|amber|red|rose|purple|indigo|cyan|orange)-(400|500)\/(10|20|30)/},
    {pattern: /from-(slate|blue|emerald|amber|red|rose|purple|indigo|cyan|orange)-(400|500|600)/},
    {pattern: /to-(slate|blue|emerald|amber|red|rose|purple|indigo|cyan|orange)-(400|500|600)/},
    // Peer checked
    {pattern: /peer-checked:bg-(blue|emerald|amber|red|rose|purple|indigo|cyan|orange|sky)-(500|600)/},
    // Hover states
    {pattern: /hover:bg-(white|slate|blue|emerald|amber|red|rose|purple|indigo)\/(5|10|20)/},
    {pattern: /hover:text-(slate|blue|emerald|amber|red|rose|purple|indigo|white)-(300|400|500)/},
    {pattern: /hover:border-(slate|blue|emerald|amber|red|rose|purple|indigo)-(500)\/(20|30)/},
    // Group hover
    {pattern: /group-hover:text-(slate|blue|emerald|amber|red|rose|purple|indigo)-(300|400|500)/},
    {pattern: /group-hover:scale-\[1\.(02|1)\]/},
    // Animations
    'animate-pulse', 'animate-spin',
    // Layout
    'hidden', 'flex', 'grid', 'block', 'inline', 'inline-flex',
    'fixed', 'absolute', 'relative', 'sticky',
    'inset-0',
    // Backdrop
    'backdrop-blur-sm', 'backdrop-blur-md', 'backdrop-blur-xl',
    // Z-index
    'z-50', 'z-[60]', 'z-[200]',
    // Common widths
    {pattern: /w-\d+/},
    {pattern: /h-\d+/},
    // Max width
    {pattern: /max-w-(sm|md|lg|xl|2xl|3xl|4xl|5xl|6xl|7xl)/},
    // Grid cols
    {pattern: /grid-cols-\d/},
    {pattern: /md:grid-cols-\d/},
    {pattern: /lg:grid-cols-\d/},
    {pattern: /xl:grid-cols-\d/},
    {pattern: /col-span-\d/},
    {pattern: /md:col-span-\d/},
    {pattern: /lg:col-span-\d/},
    // Opacity
    {pattern: /opacity-\d+/},
    // Rounded
    {pattern: /rounded-(none|sm|md|lg|xl|2xl|3xl|full)/},
    // Text sizes
    {pattern: /text-(xs|sm|base|lg|xl|2xl|3xl|4xl)/},
    {pattern: /text-\[\d+px\]/},
    // Font weight
    {pattern: /font-(normal|medium|semibold|bold|extrabold|black)/},
    // Tracking
    {pattern: /tracking-(tighter|tight|normal|wide|wider|widest)/},
    // Overflow
    'overflow-hidden', 'overflow-y-auto', 'overflow-x-auto',
    // Transitions
    'transition', 'transition-all', 'transition-colors', 'transition-transform', 'transition-opacity',
    // Cursor
    'cursor-pointer', 'cursor-not-allowed',
    // Whitespace
    'whitespace-nowrap', 'truncate',
  ],
  theme: {
    extend: {
      fontFamily: {
        sans: ['Inter', 'system-ui', '-apple-system', 'sans-serif'],
      },
    },
  },
  plugins: [],
}
