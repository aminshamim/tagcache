import type { Config } from 'tailwindcss';

export default {
  darkMode: 'class',
  content: ['./index.html', './src/**/*.{ts,tsx}'],
  theme: {
    extend: {
      colors: {
        brand: {
          primary: '#59C173',
          primaryDark: '#4BA961',
          bg: '#F7F9FA',
          card: '#FFFFFF',
          sidebar: '#59C173',
          alt: '#E8F5E9'
        },
        accent: {
          teal: '#4ECDC4',
          purple: '#9B59B6',
          orange: '#F39C12',
          red: '#E74C3C'
        },
        gray: {
          50: '#F7F9FA',
          100: '#E5E8EA',
          200: '#CBD2D6',
          300: '#A3ACB2',
          400: '#7B868E',
          500: '#5A6169',
          600: '#424851',
          700: '#2C3038',
          800: '#1A1D23',
          900: '#0D0F12'
        },
        ink: '#2C3E50'
      },
      borderRadius: {
        'xl2': '1.25rem'
      },
      boxShadow: {
        soft: '0 4px 12px -2px rgba(0,0,0,0.08), 0 2px 4px rgba(0,0,0,0.04)'
      },
      fontFamily: {
        sans: ['Inter', 'SF Pro Text', 'system-ui', 'sans-serif']
      },
      keyframes: {
        fadeIn: { '0%': { opacity: '0', transform: 'translateY(4px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } },
      },
      animation: { 'fade-in': 'fadeIn .25s ease-out' }
    },
  },
  plugins: [],
} satisfies Config;
