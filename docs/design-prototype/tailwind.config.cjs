// Design artifact only. Does not alter the production theme or its build.
module.exports = {
  content: ['./docs/design-prototype/*.html', './docs/design-prototype/assets/*prototype.js'],
  theme: { extend: {
    colors: { ink: '#182134', muted: '#596579', canvas: '#F6F7FB', line: '#DFE4ED', brand: '#7047EB', violet: {50:'#F3EFFF',100:'#E9E1FF',200:'#D4C6FB',500:'#805BF2',600:'#7047EB',700:'#5934C7'}, mint: {50:'#EBFBF4',100:'#D9F8E9',700:'#15734F'} },
    fontFamily: { sans: ['Inter','ui-sans-serif','system-ui','-apple-system','BlinkMacSystemFont','Segoe UI','PingFang SC','Microsoft YaHei','sans-serif'] }
  } }, plugins: []
};
