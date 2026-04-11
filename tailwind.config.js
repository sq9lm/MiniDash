/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './*.php',
    './includes/*.php',
    './lang/*.json',
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
