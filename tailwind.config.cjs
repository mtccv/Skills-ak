module.exports = {
  content: ['./wp-content/themes/ai-skills-hub/**/*.php'],
  theme: {
    extend: {
      colors: {
        ink: '#182134',
        muted: '#697386',
        canvas: '#f6f7fb',
        violet: { 50: '#f3efff', 100: '#e9e1ff', 500: '#805bf2', 600: '#7047eb', 700: '#5934c7' },
        mint: { 50: '#ebfbf4', 100: '#d9f8e9', 500: '#26be85', 700: '#15734f' },
      },
      fontFamily: {
        sans: ['Inter', 'ui-sans-serif', 'system-ui', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'PingFang SC', 'Microsoft YaHei', 'sans-serif'],
      },
    },
  },
  plugins: [],
};
