// Design artifact only. Does not alter the production theme or its build.
module.exports = {
  content: ['./docs/design-prototype/*.html', './docs/design-prototype/assets/*prototype.js'],
  theme: { extend: {
    colors: { ink: '#111827', muted: '#5b6370', canvas: '#f9fafb', line: '#d9dde3', control: '#858c97', brand: '#ad5e48', accent: {50:'#f8f1ee',100:'#f0dfd8',200:'#e0c1b7',500:'#b86d57',600:'#ad5e48',700:'#9f513c'} },
    fontFamily: { sans: ['Inter','ui-sans-serif','system-ui','-apple-system','BlinkMacSystemFont','Segoe UI','PingFang SC','Microsoft YaHei','sans-serif'] }
  } }, plugins: []
};
