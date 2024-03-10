import { Controller } from '@hotwired/stimulus';

import 'nouislider/dist/nouislider.css';

import noUiSlider from 'nouislider';

export default class extends Controller {
    connect() {
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

            noUiSlider.create(rangeSlider, {
                start: [options.dataStartMin, options.dataStartMax],
                connect: true,
                step: options.dataStep,
                pips: {
                    mode: 'range',
                    density: 5
                },
                tooltips: true,
                range: {
                    'min': [     0, 10 ],
                    '50%': [   500,  100 ],
                    '65%': [  1000, 250 ],
                    '80%': [  2000, 15000 ],
                    'max': [ 15000 ]
                },
                format: {
                    to: function (value) {
                        return `${parseInt(value, 10)}`;
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
    }
}
