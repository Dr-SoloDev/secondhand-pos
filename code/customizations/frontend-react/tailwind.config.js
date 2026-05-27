/** @type {import('tailwindcss').Config} */
export default {
  content: [
    "./index.html",
    "./src/**/*.{js,jsx,ts,tsx}",
  ],
  darkMode: 'class',
  theme: {
    extend: {
      fontFamily: {
        sans: ['Inter', 'system-ui', '-apple-system', 'sans-serif'],
        thai: ['"IBM Plex Sans Thai"', 'Inter', 'sans-serif'],
        mono: ['"JetBrains Mono"', 'monospace'],
      },
      colors: {
        // Linear-inspired dark palette
        bg: {
          DEFAULT: '#08090a',
          elevated: '#0f1011',
          surface: '#16181d',
          hover: '#1c1f26',
        },
        border: {
          DEFAULT: '#222326',
          subtle: '#1a1b1e',
          strong: '#2e2f33',
        },
        text: {
          primary: '#f7f8f8',
          secondary: '#9ca0a8',
          tertiary: '#62666d',
          muted: '#3d4046',
        },
        accent: {
          DEFAULT: '#5e6ad2',
          hover: '#7e88e8',
          subtle: '#5e6ad21a',
        },
        success: '#4cb782',
        warning: '#f2994a',
        danger: '#eb5757',
        info: '#56b6c2',
      },
      boxShadow: {
        'glow': '0 0 20px rgba(94, 106, 210, 0.3)',
        'card': '0 1px 2px rgba(0,0,0,0.3), 0 0 0 1px rgba(255,255,255,0.05)',
      },
      animation: {
        'fade-in': 'fadeIn 0.2s ease-out',
        'slide-up': 'slideUp 0.3s ease-out',
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
