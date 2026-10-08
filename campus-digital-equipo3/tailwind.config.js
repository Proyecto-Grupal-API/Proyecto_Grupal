/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './resources/**/*.blade.php',
    './resources/**/*.js',
    './resources/**/*.vue',
  ],
  theme: {
    extend: {
      colors: {
        campus: {
          navy: '#10285d',
          blue: '#23479b',
          bg: '#f4f7fc',
          red: '#f34a45',
          yellow: '#f5c62e',
          green: '#37b978',
        },
      },
      boxShadow: {
        card: '0 5px 18px rgba(16,40,93,.06)',
      },
    },
  },
  plugins: [],
};
