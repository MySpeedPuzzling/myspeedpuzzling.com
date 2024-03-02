/**
 * Charts
 * @requires https://github.com/gionkunz/chartist-js
*/

const charts = (() => {

  let lineChart = document.querySelectorAll('[data-line-chart]'),
      barChart = document.querySelectorAll('[data-bar-chart]'),
      pieChart = document.querySelectorAll('[data-pie-chart]');

  let sum = function(a, b) { return a + b };

  if (lineChart.length === 0 && barChart.length === 0 && pieChart.length === 0) return;

  // Create <style> tag and put it to <head> for changing colors of charts via data attributes
  let head = document.head || document.getElementsByTagName('head')[0],
      style = document.createElement('style'),
      css;
  head.appendChild(style);


  // Line chart
  for (let i = 0; i < lineChart.length; i++) {

    let data = JSON.parse(lineChart[i].dataset.lineChart),
        options = (lineChart[i].dataset.options != undefined) ? JSON.parse(lineChart[i].dataset.options) : '',
        seriesColor = lineChart[i].dataset.seriesColor,
        userColors;
            
    lineChart[i].classList.add('line-chart-' + i);

    if (seriesColor != undefined) {

      userColors = JSON.parse(seriesColor);

      for (let n = 0; n < userColors.colors.length; n++) {
        css = `
          .line-chart-${i} .ct-series:nth-child(${n+1}) .ct-line,
          .line-chart-${i} .ct-series:nth-child(${n+1}) .ct-point {
            stroke: ${userColors.colors[n]} !important;
          }
        `;
        style.appendChild(document.createTextNode(css));
      }
    }
    
    new Chartist.Line(lineChart[i], data, options);
  }


  // Bar chart
  for (let i = 0; i < barChart.length; i++) {

    let data = JSON.parse(barChart[i].dataset.barChart),
        options = (barChart[i].dataset.options != undefined) ? JSON.parse(barChart[i].dataset.options) : '',
        seriesColor = barChart[i].dataset.seriesColor,
        userColors;
    
    barChart[i].classList.add('bar-chart-' + i);

    if (seriesColor != undefined) {

      userColors = JSON.parse(seriesColor);

      for (let n = 0; n < userColors.colors.length; n++) {
        css = `
        .bar-chart-${i} .ct-series:nth-child(${n+1}) .ct-bar {
            stroke: ${userColors.colors[n]} !important;
          }
        `;
        style.appendChild(document.createTextNode(css));
      }
    }
    
    new Chartist.Bar(barChart[i], data, options);
  }


  // Pie chart
  for (let i = 0; i < pieChart.length; i++) {

    let data = JSON.parse(pieChart[i].dataset.pieChart),
        seriesColor = pieChart[i].dataset.seriesColor,
        userColors;
    
    pieChart[i].classList.add('cz-pie-chart-' + i);

    if (seriesColor != undefined) {

      userColors = JSON.parse(seriesColor);

      for (let n = 0; n < userColors.colors.length; n++) {
        css = `
        .cz-pie-chart-${i} .ct-series:nth-child(${n+1}) .ct-slice-pie {
            fill: ${userColors.colors[n]} !important;
          }
        `;
        style.appendChild(document.createTextNode(css));
      }
    }
    
    new Chartist.Pie(pieChart[i], data, {
      labelInterpolationFnc: function(value) {
        return Math.round(value / data.series.reduce(sum) * 100) + '%';
      }
    });
  }
})();

export default charts;
