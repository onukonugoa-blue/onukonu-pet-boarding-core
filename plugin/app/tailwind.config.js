/** @type {import('tailwindcss').Config} */
export default {
  content: ['./index.html', './src/**/*.{js,ts,jsx,tsx}'],
  // Prefix every utility with #opb-root so compiled rules have ID-level
  // specificity (1,1,0) and cannot be overridden by WordPress admin CSS
  // compound selectors such as .wp-admin a or #wpcontent svg.
  important: '#opb-root',
  theme: {
    extend: {
      colors: {
        brand: {
          50:  '#f0f9ff',
          100: '#e0f2fe',
          500: '#0ea5e9',
          600: '#0284c7',
          700: '#0369a1',
          800: '#075985',
          900: '#0c4a6e',
        },
      },
    },
  },
  plugins: [],
}
