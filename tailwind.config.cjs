module.exports = {
  content: ['./wp-content/themes/ai-skills-hub/**/*.php'],
  theme: {
    extend: {
      colors: {
        ink: '#111827',
        muted: '#5B6370',
        canvas: '#F9FAFB',
        // Keep existing utility contracts while adopting the approved brand palette.
        violet: { 50: '#F8F1EE', 100: '#F0DFD8', 200: '#E0C1B7', 500: '#B86D57', 600: '#AD5E48', 700: '#9F513C' },
        mint: { 50: '#F9FAFB', 100: '#F3F4F6', 500: '#5B6370', 700: '#111827' },
      },
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'PingFang SC', 'Microsoft YaHei', 'sans-serif'],
      },
    },
  },
  plugins: [],
};
