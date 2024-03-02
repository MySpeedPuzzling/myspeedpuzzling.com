/**
 * Countdown timer
*/

const countdown = (() => {

  let coundown = document.querySelectorAll('.countdown');

  if (coundown == null) return;

  for (let i = 0; i < coundown.length; i++) {

    let endDate = coundown[i].dataset.countdown,
        daysVal = coundown[i].querySelector('.countdown-days .countdown-value'),
        hoursVal = coundown[i].querySelector('.countdown-hours .countdown-value'),
        minutesVal = coundown[i].querySelector('.countdown-minutes .countdown-value'),
        secondsVal = coundown[i].querySelector('.countdown-seconds .countdown-value'),
        days, hours, minutes, seconds;
    
    endDate = new Date(endDate).getTime();

    if (isNaN(endDate)) return;

    setInterval(calculate, 1000);

    function calculate() {
      let startDate = new Date().getTime();
      
      let timeRemaining = parseInt((endDate - startDate) / 1000);
      
      if (timeRemaining >= 0) {
        days = parseInt(timeRemaining / 86400);
        timeRemaining = (timeRemaining % 86400);
        
        hours = parseInt(timeRemaining / 3600);
        timeRemaining = (timeRemaining % 3600);
        
        minutes = parseInt(timeRemaining / 60);
        timeRemaining = (timeRemaining % 60);
        
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
  }
})();

export default countdown;
