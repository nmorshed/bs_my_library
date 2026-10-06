(function () {
    'use strict';
    if (window.bsmlAppointmentCalendarsBound) return;
    window.bsmlAppointmentCalendarsBound = true;

    function loadCalendar(panel) {
        const mount = panel.querySelector('.bsml-calendar-mount');
        if (!mount || mount.querySelector('iframe')) return;
        const frame = document.createElement('iframe');
        frame.title = mount.dataset.calendarTitle;
        frame.width = '100%';
        frame.height = '900';
        if (mount.dataset.calendarPayment === '1') frame.allow = 'payment';
        frame.src = mount.dataset.calendarUrl;
        mount.appendChild(frame);
        // Acuity scans existing frames on execution, and guards against duplicate
        // frame initialization and message listeners. Re-scan for each new frame.
        const script = document.createElement('script');
        script.src = 'https://embed.acuityscheduling.com/js/embed.js';
        script.onload = script.onerror = () => script.remove();
        document.head.appendChild(script);
    }

    document.addEventListener('click', function (event) {
        const summary = event.target.closest('.bsml-calendar > summary');
        if (!summary) return;
        const panel = summary.parentElement;
        const accordion = panel.closest('.bsml-appointment-calendars');
        if (!accordion) return;
        event.preventDefault();
        const opening = !panel.open;
        accordion.querySelectorAll('.bsml-calendar').forEach(item => { item.open = false; });
        panel.open = opening;
        if (opening) loadCalendar(panel);
    });
    document.addEventListener('bsml:content-unmount', function (event) {
        // Release detached frames retained by the vendor when library tabs change.
        if (Array.isArray(window.__acuityFrames)) {
            for (let i = window.__acuityFrames.length - 1; i >= 0; i--) {
                if (event.target.contains(window.__acuityFrames[i])) window.__acuityFrames.splice(i, 1);
            }
        }
    });
})();
