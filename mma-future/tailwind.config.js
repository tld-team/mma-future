/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './**/*.php',
    './*.php',
    './**/*.html',
    './assets/**/*.js',
    './template-parts/**/*.php',
    './inc/**/*.php',
    './blocks/**/*.php'
  ],
  theme: {
    extend: {
      colors: {
        'primary': '#3366CC',
        'secondary': '#FF6B35',
      },
      fontFamily: {
        'sans': ['Inter', 'system-ui', 'sans-serif'],
      }
    },
  },
  plugins: [],
}