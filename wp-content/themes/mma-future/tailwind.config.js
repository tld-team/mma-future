/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './blocks/**/*.php',
    './inc/*.php',
    './inc/classes/*.php',
    './template-parts/*.php',
    './*.php'
  ],
  theme: {
    extend: {
      // Screen breakpoints
      screens: {
        'xs': '480px',
      },
      // Boje (prema style guide-u)
      colors: {
        primary: {
          50: '#e6f0ff',
          100: '#cce0ff',
          200: '#99c2ff',
          300: '#66a3ff',
          400: '#3385ff',
          500: '#0047A8', // Osnovna plava (button bg)
          600: '#003d8f',
          700: '#003376',
          800: '#00295d',
          900: '#001f44',
          DEFAULT: '#0047A8',
        },
        secondary: {
          50: '#f9fafb',
          100: '#f3f4f6',
          200: '#e5e7eb',
          300: '#d1d5db',
          400: '#9ca3af',
          500: '#647488', // Muted iz style guide-a
          600: '#4b5563',
          700: '#374151',
          800: '#1f2937', // Body text boja
          900: '#0B1220', // Heading boja
          DEFAULT: '#1f2937',
        },
        accent: {
          50: '#fef8f3',
          100: '#fdf1e7',
          200: '#fbe3cf',
          300: '#f9d5b7',
          400: '#f7c79f',
          500: '#DC2626', // 2nd neutral (bakarna/narandžasta)
          600: '#c32020',
          700: '#aa1b1b',
          800: '#911616',
          900: '#781111',
          DEFAULT: '#DC2626',
        },
        success: '#16A34A',
        warning: '#F59E0B',
        danger: '#DC2626',
        muted: '#647488',
        heading: '#0B1220',
        body: '#1F2937',
        'button-text': '#F7FAFC',
        border: '#E5E7EB',
      },

      // Tipografija (prema style guide-u)
      fontFamily: {
        'sans': ['Inter', 'ui-sans-serif', 'system-ui'],
        'serif': ['ui-serif', 'Georgia'],
        'mono': ['ui-monospace', 'SFMono-Regular'],
        'display': ['Sora', 'ui-sans-serif'], // Heading font
        'body': ['Inter', 'ui-sans-serif'], // Body font
        'heading': ['Sora', 'ui-sans-serif'],
      },

      // Veličine fontova (base 18px prema style guide-u)
      fontSize: {
        'xs': ['0.75rem', { lineHeight: '1rem' }],
        'sm': ['0.875rem', { lineHeight: '1.25rem' }],
        'base': ['1.125rem', { lineHeight: '1.75rem' }], // 18px za body text
        'lg': ['1.25rem', { lineHeight: '1.75rem' }],
        'xl': ['1.5rem', { lineHeight: '2rem' }],
        '2xl': ['1.875rem', { lineHeight: '2.25rem' }],
        '3xl': ['2.25rem', { lineHeight: '2.5rem' }],
        '4xl': ['3rem', { lineHeight: '1' }],
        '5xl': ['3.75rem', { lineHeight: '1' }],
        '6xl': ['4.5rem', { lineHeight: '1' }],
        '7xl': ['6rem', { lineHeight: '1' }],
        '8xl': ['8rem', { lineHeight: '1' }],
        '9xl': ['10rem', { lineHeight: '1' }],
      },

      // Font weight
      fontWeight: {
        'thin': 100,
        'extralight': 200,
        'light': 300,
        'normal': 400,
        'medium': 500,
        'semibold': 600,
        'bold': 700,
        'extrabold': 800,
        'black': 900,
      },

      // Spacing (razmaci)
      spacing: {
        '18': '4.5rem',
        '88': '22rem',
        '128': '32rem',
        '144': '36rem',
      },

      // Border radius
      borderRadius: {
        '4xl': '2rem',
        '5xl': '2.5rem',
      },

      // Animacije
      animation: {
        'fade-in': 'fadeIn 0.5s ease-in-out',
        'slide-up': 'slideUp 0.3s ease-out',
        'pulse-slow': 'pulse 3s infinite',
      },
      backgroundImage: {
        'gradient-mma': 'linear-gradient(135deg, #0B1220 0%, #1f2937 100%)',
        'gradient-mma-light': 'linear-gradient(135deg, #f9fafb 0%, #e5e7eb 100%)',
        'gradient-stats': 'linear-gradient(90deg, rgba(11, 18, 32, 0.9) 0%, rgba(31, 41, 55, 0.7) 100%)',
        'gradient-stats-light': 'linear-gradient(90deg, rgba(249, 250, 251, 0.9) 0%, rgba(229, 231, 235, 0.7) 100%)',
        'section-divider': 'linear-gradient(90deg, transparent 0%, #0047A8 50%, transparent 100%)',
        'section-divider-light': 'linear-gradient(90deg, transparent 0%, #0047A8 50%, transparent 100%)',
      },
      boxShadow: {
        'fighter': '0 10px 15px rgba(0, 0, 0, 0.5)',
        'fighter-light': '0 10px 15px rgba(0, 0, 0, 0.1)',
        'card': '0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06)',
        'card-dark': '0 4px 6px -1px rgba(0, 0, 0, 0.3), 0 2px 4px -1px rgba(0, 0, 0, 0.2)',
      },

      keyframes: {
        fadeIn: {
          '0%': { opacity: '0' },
          '100%': { opacity: '1' },
        },
        slideUp: {
          '0%': { transform: 'translateY(10px)', opacity: '0' },
          '100%': { transform: 'translateY(0)', opacity: '1' },
        },
      },
    },
  },
  plugins: [],
}