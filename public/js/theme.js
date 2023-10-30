function ownKeys(object, enumerableOnly) { var keys = Object.keys(object); if (Object.getOwnPropertySymbols) { var symbols = Object.getOwnPropertySymbols(object); enumerableOnly && (symbols = symbols.filter(function (sym) { return Object.getOwnPropertyDescriptor(object, sym).enumerable; })), keys.push.apply(keys, symbols); } return keys; }
function _objectSpread(target) { for (var i = 1; i < arguments.length; i++) { var source = null != arguments[i] ? arguments[i] : {}; i % 2 ? ownKeys(Object(source), !0).forEach(function (key) { _defineProperty(target, key, source[key]); }) : Object.getOwnPropertyDescriptors ? Object.defineProperties(target, Object.getOwnPropertyDescriptors(source)) : ownKeys(Object(source)).forEach(function (key) { Object.defineProperty(target, key, Object.getOwnPropertyDescriptor(source, key)); }); } return target; }
function _defineProperty(obj, key, value) { key = _toPropertyKey(key); if (key in obj) { Object.defineProperty(obj, key, { value: value, enumerable: true, configurable: true, writable: true }); } else { obj[key] = value; } return obj; }
function _toPropertyKey(arg) { var key = _toPrimitive(arg, "string"); return _typeof(key) === "symbol" ? key : String(key); }
function _toPrimitive(input, hint) { if (_typeof(input) !== "object" || input === null) return input; var prim = input[Symbol.toPrimitive]; if (prim !== undefined) { var res = prim.call(input, hint || "default"); if (_typeof(res) !== "object") return res; throw new TypeError("@@toPrimitive must return a primitive value."); } return (hint === "string" ? String : Number)(input); }
function _typeof(obj) { "@babel/helpers - typeof"; return _typeof = "function" == typeof Symbol && "symbol" == typeof Symbol.iterator ? function (obj) { return typeof obj; } : function (obj) { return obj && "function" == typeof Symbol && obj.constructor === Symbol && obj !== Symbol.prototype ? "symbol" : typeof obj; }, _typeof(obj); }
/**
 * Cartzilla | Bootstrap E-Commerce Template
 * Copyright 2023 Createx Studio
 * Theme core scripts
 * 
 * @author Createx Studio
 * @version 2.5.1
 */

(function () {
  'use strict';

  /**
   * Enable sticky behavior of navigation bar on page scroll
  */
  var stickyNavbar = function () {
    var navbar = document.querySelector('.navbar-sticky');
    if (navbar == null) return;
    var navbarClass = navbar.classList,
      navbarH = navbar.offsetHeight,
      scrollOffset = 500;
    window.addEventListener('scroll', function (e) {
      if (navbar.classList.contains('position-absolute')) {
        if (e.currentTarget.pageYOffset > scrollOffset) {
          navbar.classList.add('navbar-stuck');
        } else {
          navbar.classList.remove('navbar-stuck');
        }
      } else {
        if (e.currentTarget.pageYOffset > scrollOffset) {
          document.body.style.paddingTop = navbarH + 'px';
          navbar.classList.add('navbar-stuck');
        } else {
          document.body.style.paddingTop = '';
          navbar.classList.remove('navbar-stuck');
        }
      }
    });
  }();

  /**
   * Menu toggle for 3 level navbar stuck state
  */

  var stuckNavbarMenuToggle = function () {
    var toggler = document.querySelector('.navbar-stuck-toggler'),
      stuckMenu = document.querySelector('.navbar-stuck-menu');
    if (toggler == null) return;
    toggler.addEventListener('click', function (e) {
      stuckMenu.classList.toggle('show');
      e.preventDefault();
    });
  }();

  /**
   * Cascading (Masonry) grid layout
   * 
   * @requires https://github.com/desandro/imagesloaded
   * @requires https://github.com/Vestride/Shuffle
  */

  var masonryGrid = function () {
    var grid = document.querySelectorAll('.masonry-grid'),
      masonry;
    if (grid === null) return;
    for (var i = 0; i < grid.length; i++) {
      masonry = new Shuffle(grid[i], {
        itemSelector: '.masonry-grid-item',
        sizer: '.masonry-grid-item'
      });
      imagesLoaded(grid[i]).on('progress', function () {
        masonry.layout();
      });
    }
  }();

  /**
   * Toggling password visibility in password input
  */

  var passwordVisibilityToggle = function () {
    var elements = document.querySelectorAll('.password-toggle');
    var _loop = function _loop() {
      var passInput = elements[i].querySelector('.form-control'),
        passToggle = elements[i].querySelector('.password-toggle-btn');
      passToggle.addEventListener('click', function (e) {
        if (e.target.type !== 'checkbox') return;
        if (e.target.checked) {
          passInput.type = 'text';
        } else {
          passInput.type = 'password';
        }
      }, false);
    };
    for (var i = 0; i < elements.length; i++) {
      _loop();
    }
  }();

  /**
   * Custom file drag and drop area
  */

  var fileDropArea = function () {
    var fileArea = document.querySelectorAll('.file-drop-area');
    var _loop2 = function _loop2() {
      var input = fileArea[i].querySelector('.file-drop-input'),
        message = fileArea[i].querySelector('.file-drop-message'),
        icon = fileArea[i].querySelector('.file-drop-icon'),
        button = fileArea[i].querySelector('.file-drop-btn');
      button.addEventListener('click', function () {
        input.click();
      });
      input.addEventListener('change', function () {
        if (input.files && input.files[0]) {
          var reader = new FileReader();
          reader.onload = function (e) {
            var fileData = e.target.result;
            var fileName = input.files[0].name;
            message.innerHTML = fileName;
            if (fileData.startsWith('data:image')) {
              var image = new Image();
              image.src = fileData;
              image.onload = function () {
                icon.className = 'file-drop-preview img-thumbnail rounded';
                icon.innerHTML = '<img src="' + image.src + '" alt="' + fileName + '">';
              };
            } else if (fileData.startsWith('data:video')) {
              icon.innerHTML = '';
              icon.className = '';
              icon.className = 'file-drop-icon ci-video';
            } else {
              icon.innerHTML = '';
              icon.className = '';
              icon.className = 'file-drop-icon ci-document';
            }
          };
          reader.readAsDataURL(input.files[0]);
        }
      });
    };
    for (var i = 0; i < fileArea.length; i++) {
      _loop2();
    }
  }();

  /**
   * Form validation
  */

  var formValidation = function () {
    var selector = 'needs-validation';
    window.addEventListener('load', function () {
      // Fetch all the forms we want to apply custom Bootstrap validation styles to
      var forms = document.getElementsByClassName(selector);
      // Loop over them and prevent submission
      var validation = Array.prototype.filter.call(forms, function (form) {
        form.addEventListener('submit', function (e) {
          if (form.checkValidity() === false) {
            e.preventDefault();
            e.stopPropagation();
          }
          form.classList.add('was-validated');
        }, false);
      });
    }, false);
  }();

  /**
   * Anchor smooth scrolling
   * @requires https://github.com/cferdinandi/smooth-scroll/
  */

  var smoothScroll = function () {
    var selector = '[data-scroll]',
      fixedHeader = '[data-scroll-header]',
      scroll = new SmoothScroll(selector, {
        speed: 800,
        speedAsDuration: true,
        offset: 40,
        header: fixedHeader,
        updateURL: false
      });
  }();

  /**
   * Animate scroll to top button in/off view
  */

  var scrollTopButton = function () {
    var element = document.querySelector('.btn-scroll-top'),
      scrollOffset = 600;
    if (element == null) return;
    var offsetFromTop = parseInt(scrollOffset, 10);
    window.addEventListener('scroll', function (e) {
      if (e.currentTarget.pageYOffset > offsetFromTop) {
        element.classList.add('show');
      } else {
        element.classList.remove('show');
      }
    });
  }();

  /**
   * Tooltip
   * @requires https://getbootstrap.com
   * @requires https://popper.js.org/
  */

  var tooltip = function () {
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
      return new bootstrap.Tooltip(tooltipTriggerEl, {
        trigger: 'hover'
      });
    });
  }();

  /**
   * Popover
   * @requires https://getbootstrap.com
   * @requires https://popper.js.org/
  */

  var popover = function () {
    var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
      return new bootstrap.Popover(popoverTriggerEl);
    });
  }();

  /**
   * Toast
   * @requires https://getbootstrap.com
  */

  var toast = function () {
    var toastElList = [].slice.call(document.querySelectorAll('.toast'));
    var toastList = toastElList.map(function (toastEl) {
      return new bootstrap.Toast(toastEl);
    });
  }();

  /**
   * Disable dropdown autohide when select is clicked
  */

  var disableDropdownAutohide = function () {
    var elements = document.querySelectorAll('.disable-autohide .form-select');
    for (var i = 0; i < elements.length; i++) {
      elements[i].addEventListener('click', function (e) {
        e.stopPropagation();
      });
    }
  }();

  /**
   * Content carousel with extensive options to control behaviour and appearance
   * @requires https://github.com/ganlanyuan/tiny-slider
  */

  var carousel = function () {
    // forEach function
    var forEach = function forEach(array, callback, scope) {
      for (var i = 0; i < array.length; i++) {
        callback.call(scope, i, array[i]); // passes back stuff we need
      }
    };

    // Carousel initialisation
    var carousels = document.querySelectorAll('.tns-carousel .tns-carousel-inner');
    forEach(carousels, function (index, value) {
      var defaults = {
        container: value,
        controlsText: ['<i class="ci-arrow-left"></i>', '<i class="ci-arrow-right"></i>'],
        navPosition: 'bottom',
        mouseDrag: true,
        speed: 500,
        autoplayHoverPause: true,
        autoplayButtonOutput: false
      };
      var userOptions;
      if (value.dataset.carouselOptions != undefined) userOptions = JSON.parse(value.dataset.carouselOptions);
      var options = Object.assign({}, defaults, userOptions);
      var carousel = tns(options);
    });
  }();

  /**
   * Gallery like styled lightbox component for presenting various types of media
   * @requires https://github.com/sachinchoolur/lightGallery
  */

  var gallery = function () {
    var gallery = document.querySelectorAll('.gallery');
    if (gallery.length) {
      for (var i = 0; i < gallery.length; i++) {
        var thumbnails = gallery[i].dataset.thumbnails ? true : false,
          video = gallery[i].dataset.video ? true : false,
          defaultPlugins = [lgZoom, lgFullscreen],
          videoPlugin = video ? [lgVideo] : [],
          thumbnailPlugin = thumbnails ? [lgThumbnail] : [],
          plugins = [].concat(defaultPlugins, videoPlugin, thumbnailPlugin);
        lightGallery(gallery[i], {
          selector: '.gallery-item',
          plugins: plugins,
          licenseKey: 'D4194FDD-48924833-A54AECA3-D6F8E646',
          download: false,
          autoplayVideoOnSlide: true,
          zoomFromOrigin: false,
          youtubePlayerParams: {
            modestbranding: 1,
            showinfo: 0,
            rel: 0
          },
          vimeoPlayerParams: {
            byline: 0,
            portrait: 0,
            color: '6366f1'
          }
        });
      }
    }
  }();

  /**
   * Shop product page gallery with thumbnails
   * @requires https://github.com/sachinchoolur/lightgallery.js
  */

  var productGallery = function () {
    var gallery = document.querySelectorAll('.product-gallery');
    if (gallery.length) {
      var _loop3 = function _loop3(i) {
        var thumbnails = gallery[i].querySelectorAll('.product-gallery-thumblist-item:not(.video-item)'),
          previews = gallery[i].querySelectorAll('.product-gallery-preview-item'),
          videos = gallery[i].querySelectorAll('.product-gallery-thumblist-item.video-item');
        for (var n = 0; n < thumbnails.length; n++) {
          thumbnails[n].addEventListener('click', changePreview);
        }

        // Changer preview function
        function changePreview(e) {
          e.preventDefault();
          for (var _i = 0; _i < thumbnails.length; _i++) {
            previews[_i].classList.remove('active');
            thumbnails[_i].classList.remove('active');
          }
          this.classList.add('active');
          gallery[i].querySelector(this.getAttribute('href')).classList.add('active');
        }

        // Video thumbnail - open video in lightbox
        for (var m = 0; m < videos.length; m++) {
          lightGallery(videos[m], {
            selector: 'this',
            plugins: [lgVideo],
            licenseKey: 'D4194FDD-48924833-A54AECA3-D6F8E646',
            download: false,
            autoplayVideoOnSlide: true,
            zoomFromOrigin: false,
            youtubePlayerParams: {
              modestbranding: 1,
              showinfo: 0,
              rel: 0,
              controls: 0
            },
            vimeoPlayerParams: {
              byline: 0,
              portrait: 0,
              color: 'fe696a'
            }
          });
        }
      };
      for (var i = 0; i < gallery.length; i++) {
        _loop3(i);
      }
    }
  }();

  /**
   * Image zoom on hover (used inside Product Gallery)
   * @requires https://github.com/imgix/drift
  */

  var imageZoom = function () {
    var images = document.querySelectorAll('.image-zoom');
    for (var i = 0; i < images.length; i++) {
      new Drift(images[i], {
        paneContainer: images[i].parentElement.querySelector('.image-zoom-pane')
      });
    }
  }();

  /**
   * Countdown timer
  */

  var countdown = function () {
    var coundown = document.querySelectorAll('.countdown');
    if (coundown == null) return;
    var _loop4 = function _loop4() {
      var endDate = coundown[i].dataset.countdown,
        daysVal = coundown[i].querySelector('.countdown-days .countdown-value'),
        hoursVal = coundown[i].querySelector('.countdown-hours .countdown-value'),
        minutesVal = coundown[i].querySelector('.countdown-minutes .countdown-value'),
        secondsVal = coundown[i].querySelector('.countdown-seconds .countdown-value'),
        days,
        hours,
        minutes,
        seconds;
      endDate = new Date(endDate).getTime();
      if (isNaN(endDate)) return {
        v: void 0
      };
      setInterval(calculate, 1000);
      function calculate() {
        var startDate = new Date().getTime();
        var timeRemaining = parseInt((endDate - startDate) / 1000);
        if (timeRemaining >= 0) {
          days = parseInt(timeRemaining / 86400);
          timeRemaining = timeRemaining % 86400;
          hours = parseInt(timeRemaining / 3600);
          timeRemaining = timeRemaining % 3600;
          minutes = parseInt(timeRemaining / 60);
          timeRemaining = timeRemaining % 60;
          seconds = parseInt(timeRemaining);
          if (daysVal != null) {
            daysVal.innerHTML = parseInt(days, 10);
          }
          if (hoursVal != null) {
            hoursVal.innerHTML = hours < 10 ? '0' + hours : hours;
          }
          if (minutesVal != null) {
            minutesVal.innerHTML = minutes < 10 ? '0' + minutes : minutes;
          }
          if (secondsVal != null) {
            secondsVal.innerHTML = seconds < 10 ? '0' + seconds : seconds;
          }
        } else {
          return;
        }
      }
    };
    for (var i = 0; i < coundown.length; i++) {
      var _ret = _loop4();
      if (_typeof(_ret) === "object") return _ret.v;
    }
  }();

  /**
   * Charts
   * @requires https://github.com/gionkunz/chartist-js
  */

  var charts = function () {
    var lineChart = document.querySelectorAll('[data-line-chart]'),
      barChart = document.querySelectorAll('[data-bar-chart]'),
      pieChart = document.querySelectorAll('[data-pie-chart]');
    var sum = function sum(a, b) {
      return a + b;
    };
    if (lineChart.length === 0 && barChart.length === 0 && pieChart.length === 0) return;

    // Create <style> tag and put it to <head> for changing colors of charts via data attributes
    var head = document.head || document.getElementsByTagName('head')[0],
      style = document.createElement('style'),
      css;
    head.appendChild(style);

    // Line chart
    for (var i = 0; i < lineChart.length; i++) {
      var data = JSON.parse(lineChart[i].dataset.lineChart),
        options = lineChart[i].dataset.options != undefined ? JSON.parse(lineChart[i].dataset.options) : '',
        seriesColor = lineChart[i].dataset.seriesColor,
        userColors = void 0;
      lineChart[i].classList.add('line-chart-' + i);
      if (seriesColor != undefined) {
        userColors = JSON.parse(seriesColor);
        for (var n = 0; n < userColors.colors.length; n++) {
          css = "\n          .line-chart-".concat(i, " .ct-series:nth-child(").concat(n + 1, ") .ct-line,\n          .line-chart-").concat(i, " .ct-series:nth-child(").concat(n + 1, ") .ct-point {\n            stroke: ").concat(userColors.colors[n], " !important;\n          }\n        ");
          style.appendChild(document.createTextNode(css));
        }
      }
      new Chartist.Line(lineChart[i], data, options);
    }

    // Bar chart
    for (var _i2 = 0; _i2 < barChart.length; _i2++) {
      var _data = JSON.parse(barChart[_i2].dataset.barChart),
        _options = barChart[_i2].dataset.options != undefined ? JSON.parse(barChart[_i2].dataset.options) : '',
        _seriesColor = barChart[_i2].dataset.seriesColor,
        _userColors = void 0;
      barChart[_i2].classList.add('bar-chart-' + _i2);
      if (_seriesColor != undefined) {
        _userColors = JSON.parse(_seriesColor);
        for (var _n = 0; _n < _userColors.colors.length; _n++) {
          css = "\n        .bar-chart-".concat(_i2, " .ct-series:nth-child(").concat(_n + 1, ") .ct-bar {\n            stroke: ").concat(_userColors.colors[_n], " !important;\n          }\n        ");
          style.appendChild(document.createTextNode(css));
        }
      }
      new Chartist.Bar(barChart[_i2], _data, _options);
    }

    // Pie chart
    var _loop5 = function _loop5() {
      var data = JSON.parse(pieChart[_i3].dataset.pieChart),
        seriesColor = pieChart[_i3].dataset.seriesColor,
        userColors;
      pieChart[_i3].classList.add('cz-pie-chart-' + _i3);
      if (seriesColor != undefined) {
        userColors = JSON.parse(seriesColor);
        for (var _n2 = 0; _n2 < userColors.colors.length; _n2++) {
          css = "\n        .cz-pie-chart-".concat(_i3, " .ct-series:nth-child(").concat(_n2 + 1, ") .ct-slice-pie {\n            fill: ").concat(userColors.colors[_n2], " !important;\n          }\n        ");
          style.appendChild(document.createTextNode(css));
        }
      }
      new Chartist.Pie(pieChart[_i3], data, {
        labelInterpolationFnc: function labelInterpolationFnc(value) {
          return Math.round(value / data.series.reduce(sum) * 100) + '%';
        }
      });
    };
    for (var _i3 = 0; _i3 < pieChart.length; _i3++) {
      _loop5();
    }
  }();

  /**
   * Open YouTube video in lightbox
   * @requires https://github.com/sachinchoolur/lightGallery
  */

  var videoButton = function () {
    var button = document.querySelectorAll('[data-bs-toggle="video"]');
    if (button.length) {
      for (var i = 0; i < button.length; i++) {
        lightGallery(button[i], {
          selector: 'this',
          plugins: [lgVideo],
          licenseKey: 'D4194FDD-48924833-A54AECA3-D6F8E646',
          download: false,
          youtubePlayerParams: {
            modestbranding: 1,
            showinfo: 0,
            rel: 0
          },
          vimeoPlayerParams: {
            byline: 0,
            portrait: 0,
            color: '6366f1'
          }
        });
      }
    }
  }();

  /**
   * Ajaxify MailChimp subscription form
  */

  var subscriptionForm = function () {
    var form = document.querySelectorAll('.subscription-form');
    if (form === null) return;
    var _loop6 = function _loop6() {
      var button = form[i].querySelector('button[type="submit"]'),
        buttonText = button.innerHTML,
        input = form[i].querySelector('.form-control'),
        antispam = form[i].querySelector('.subscription-form-antispam'),
        status = form[i].querySelector('.subscription-status');
      form[i].addEventListener('submit', function (e) {
        if (e) e.preventDefault();
        if (antispam.value !== '') return;
        register(this, button, input, buttonText, status);
      });
    };
    for (var i = 0; i < form.length; i++) {
      _loop6();
    }
    var register = function register(form, button, input, buttonText, status) {
      button.innerHTML = 'Sending...';

      // Get url for MailChimp
      var url = form.action.replace('/post?', '/post-json?');

      // Add form data to object
      var data = '&' + input.name + '=' + encodeURIComponent(input.value);

      // Create and add post script to the DOM
      var script = document.createElement('script');
      script.src = url + '&c=callback' + data;
      document.body.appendChild(script);

      // Callback function
      var callback = 'callback';
      window[callback] = function (response) {
        // Remove post script from the DOM
        delete window[callback];
        document.body.removeChild(script);

        // Change button text back to initial
        button.innerHTML = buttonText;

        // Display content and apply styling to response message conditionally
        if (response.result == 'success') {
          input.classList.remove('is-invalid');
          input.classList.add('is-valid');
          status.classList.remove('status-error');
          status.classList.add('status-success');
          status.innerHTML = response.msg;
          setTimeout(function () {
            input.classList.remove('is-valid');
            status.innerHTML = '';
            status.classList.remove('status-success');
          }, 6000);
        } else {
          input.classList.remove('is-valid');
          input.classList.add('is-invalid');
          status.classList.remove('status-success');
          status.classList.add('status-error');
          status.innerHTML = response.msg.substring(4);
          setTimeout(function () {
            input.classList.remove('is-invalid');
            status.innerHTML = '';
            status.classList.remove('status-error');
          }, 6000);
        }
      };
    };
  }();

  /**
   * Range slider
   * @requires https://github.com/leongersen/noUiSlider
  */

  var rangeSlider = function () {
    var rangeSliderWidget = document.querySelectorAll('.range-slider');
    var _loop7 = function _loop7() {
      var rangeSlider = rangeSliderWidget[i].querySelector('.range-slider-ui'),
        valueMinInput = rangeSliderWidget[i].querySelector('.range-slider-value-min'),
        valueMaxInput = rangeSliderWidget[i].querySelector('.range-slider-value-max');
      var options = {
        dataStartMin: parseInt(rangeSliderWidget[i].dataset.startMin, 10),
        dataStartMax: parseInt(rangeSliderWidget[i].dataset.startMax, 10),
        dataMin: parseInt(rangeSliderWidget[i].dataset.min, 10),
        dataMax: parseInt(rangeSliderWidget[i].dataset.max, 10),
        dataStep: parseInt(rangeSliderWidget[i].dataset.step, 10)
      };
      var dataCurrency = rangeSliderWidget[i].dataset.currency;
      noUiSlider.create(rangeSlider, {
        start: [options.dataStartMin, options.dataStartMax],
        connect: true,
        step: options.dataStep,
        pips: {
          mode: 'count',
          values: 5
        },
        tooltips: true,
        range: {
          'min': options.dataMin,
          'max': options.dataMax
        },
        format: {
          to: function to(value) {
            return "".concat(dataCurrency ? dataCurrency : '$').concat(parseInt(value, 10));
          },
          from: function from(value) {
            return Number(value);
          }
        }
      });
      rangeSlider.noUiSlider.on('update', function (values, handle) {
        var value = values[handle];
        value = value.replace(/\D/g, '');
        if (handle) {
          valueMaxInput.value = Math.round(value);
        } else {
          valueMinInput.value = Math.round(value);
        }
      });
      valueMinInput.addEventListener('change', function () {
        rangeSlider.noUiSlider.set([this.value, null]);
      });
      valueMaxInput.addEventListener('change', function () {
        rangeSlider.noUiSlider.set([null, this.value]);
      });
    };
    for (var i = 0; i < rangeSliderWidget.length; i++) {
      _loop7();
    }
  }();

  /**
   * Filter list of items by typing in the search field
  */

  var filterList = function () {
    var filterListWidget = document.querySelectorAll('.widget-filter');
    var _loop8 = function _loop8() {
      var filterInput = filterListWidget[i].querySelector('.widget-filter-search'),
        filterList = filterListWidget[i].querySelector('.widget-filter-list'),
        filterItems = filterList.querySelectorAll('.widget-filter-item');
      if (!filterInput) {
        return "continue";
      }
      filterInput.addEventListener('keyup', filterListFunc);
      function filterListFunc() {
        var filterValue = filterInput.value.toLowerCase();
        for (var _i4 = 0; _i4 < filterItems.length; _i4++) {
          var filterText = filterItems[_i4].querySelector('.widget-filter-item-text').innerHTML;
          if (filterText.toLowerCase().indexOf(filterValue) > -1) {
            filterItems[_i4].classList.remove('d-none');
          } else {
            filterItems[_i4].classList.add('d-none');
          }
        }
      }
    };
    for (var i = 0; i < filterListWidget.length; i++) {
      var _ret2 = _loop8();
      if (_ret2 === "continue") continue;
    }
  }();

  /**
   * Data filtering (Comparison table)
  */

  var dataFilter = function () {
    var trigger = document.querySelector('[data-filter-trigger]'),
      target = document.querySelectorAll('[data-filter-target]');
    if (trigger === null) return;
    trigger.addEventListener('change', function () {
      var selected = this.options[this.selectedIndex].value.toLowerCase();
      if (selected === 'all') {
        for (var i = 0; i < target.length; i++) {
          target[i].classList.remove('d-none');
        }
      } else {
        for (var n = 0; n < target.length; n++) {
          target[n].classList.add('d-none');
        }
        document.querySelector('#' + selected).classList.remove('d-none');
      }
    });
  }();

  /**
   * Updated the text of the label when radio button changes (mainly for color options)
  */

  var labelUpdate = function () {
    var radioBtns = document.querySelectorAll('[data-bs-label]');
    for (var i = 0; i < radioBtns.length; i++) {
      radioBtns[i].addEventListener('change', function () {
        var target = this.dataset.bsLabel;
        try {
          document.getElementById(target).textContent = this.value;
        } catch (err) {
          if (err.message = "Cannot set property 'textContent' of null") {
            console.error('Make sure the [data-label] matches with the id of the target element you want to change text of!');
          }
        }
      });
    }
  }();

  /**
   * Change tabs with radio buttons
  */

  var radioTab = function () {
    var radioBtns = document.querySelectorAll('[data-bs-toggle="radioTab"]');
    for (var i = 0; i < radioBtns.length; i++) {
      radioBtns[i].addEventListener('click', function () {
        var target = this.dataset.bsTarget,
          parent = document.querySelector(this.dataset.bsParent),
          children = parent.querySelectorAll('.radio-tab-pane');
        children.forEach(function (element) {
          element.classList.remove('active');
        });
        document.querySelector(target).classList.add('active');
      });
    }
  }();

  /**
   * Change tabs with radio buttons
  */

  var creditCard = function () {
    var selector = document.querySelector('.credit-card-form');
    if (selector === null) return;
    var card = new Card({
      form: selector,
      container: '.credit-card-wrapper'
    });
  }();

  /**
   * Master checkbox that checkes / unchecks all target checkboxes at once
  */

  var masterCheckbox = function () {
    var masterCheckbox = document.querySelectorAll('[data-master-checkbox-for]');
    if (masterCheckbox.length === 0) return;
    for (var i = 0; i < masterCheckbox.length; i++) {
      masterCheckbox[i].addEventListener('change', function () {
        var targetWrapper = document.querySelector(this.dataset.masterCheckboxFor),
          targetCheckboxes = targetWrapper.querySelectorAll('input[type="checkbox"]');
        if (this.checked) {
          for (var n = 0; n < targetCheckboxes.length; n++) {
            targetCheckboxes[n].checked = true;
            if (targetCheckboxes[n].dataset.checkboxToggleClass) {
              document.querySelector(targetCheckboxes[n].dataset.target).classList.add(targetCheckboxes[n].dataset.checkboxToggleClass);
            }
          }
        } else {
          for (var m = 0; m < targetCheckboxes.length; m++) {
            targetCheckboxes[m].checked = false;
            if (targetCheckboxes[m].dataset.checkboxToggleClass) {
              document.querySelector(targetCheckboxes[m].dataset.target).classList.remove(targetCheckboxes[m].dataset.checkboxToggleClass);
            }
          }
        }
      });
    }
  }();

  /**
   * Date / time picker
   * @requires https://github.com/flatpickr/flatpickr
   */

  var datePicker = function () {
    var picker = document.querySelectorAll('.date-picker');
    if (picker.length === 0) return;
    for (var i = 0; i < picker.length; i++) {
      var defaults = {
        disableMobile: 'true'
      };
      var userOptions = void 0;
      if (picker[i].dataset.datepickerOptions != undefined) userOptions = JSON.parse(picker[i].dataset.datepickerOptions);
      var linkedInput = picker[i].classList.contains('date-range') ? {
        "plugins": [new rangePlugin({
          input: picker[i].dataset.linkedInput
        })]
      } : '{}';
      var options = _objectSpread(_objectSpread(_objectSpread({}, defaults), linkedInput), userOptions);
      flatpickr(picker[i], options);
    }
  }();

  /**
   * Force dropdown to work as select box
  */

  var dropdownSelect = function () {
    var dropdownSelectList = document.querySelectorAll('[data-bs-toggle="select"]');
    var _loop9 = function _loop9() {
      var dropdownItems = dropdownSelectList[i].querySelectorAll('.dropdown-item'),
        dropdownToggleLabel = dropdownSelectList[i].querySelector('.dropdown-toggle-label'),
        hiddenInput = dropdownSelectList[i].querySelector('input[type="hidden"]');
      for (var n = 0; n < dropdownItems.length; n++) {
        dropdownItems[n].addEventListener('click', function (e) {
          e.preventDefault();
          var dropdownLabel = this.querySelector('.dropdown-item-label').innerText;
          dropdownToggleLabel.innerText = dropdownLabel;
          if (hiddenInput !== null) {
            hiddenInput.value = dropdownLabel;
          }
        });
      }
    };
    for (var i = 0; i < dropdownSelectList.length; i++) {
      _loop9();
    }
  }();
})();