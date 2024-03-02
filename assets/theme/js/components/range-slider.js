/**
 * Range slider
 * @requires https://github.com/leongersen/noUiSlider
*/

const rangeSlider = (() => {

  let rangeSliderWidget = document.querySelectorAll('.range-slider');

  for (let i = 0; i < rangeSliderWidget.length; i++) {

    let rangeSlider = rangeSliderWidget[i].querySelector('.range-slider-ui'),
        valueMinInput = rangeSliderWidget[i].querySelector('.range-slider-value-min'),
        valueMaxInput = rangeSliderWidget[i].querySelector('.range-slider-value-max');

    let options = {
      dataStartMin: parseInt(rangeSliderWidget[i].dataset.startMin, 10),
      dataStartMax: parseInt(rangeSliderWidget[i].dataset.startMax, 10),
      dataMin: parseInt(rangeSliderWidget[i].dataset.min, 10),
      dataMax: parseInt(rangeSliderWidget[i].dataset.max, 10),
      dataStep: parseInt(rangeSliderWidget[i].dataset.step, 10)
    }

    let dataCurrency = rangeSliderWidget[i].dataset.currency;

    noUiSlider.create(rangeSlider, {
      start: [options.dataStartMin, options.dataStartMax],
      connect: true,
      step: options.dataStep,
      pips: {mode: 'count', values: 5},
      tooltips: true,
      range: {
        'min': options.dataMin,
        'max': options.dataMax
      },
      format: {
        to: function (value) {
          return `${dataCurrency ? dataCurrency : '$'}${parseInt(value, 10)}`;
        },
        from: function (value) {
          return Number(value);
        }
      }
    });

    rangeSlider.noUiSlider.on('update', (values, handle) => {
      let value = values[handle];
      value = value.replace(/\D/g,'');
      if (handle) {
        valueMaxInput.value = Math.round(value);
      } else {
        valueMinInput.value = Math.round(value);
      }
    });
    
    valueMinInput.addEventListener('change', function() {
      rangeSlider.noUiSlider.set([this.value, null]);
    });
    
    valueMaxInput.addEventListener('change', function() {
      rangeSlider.noUiSlider.set([null, this.value]);
    });
    
  }
})();

export default rangeSlider;
