<?php
declare(strict_types=1);
?>
<script>
(function () {
  function applyLanguage(lang) {
    if (lang !== 'en' && lang !== 'th') {
      return;
    }
    document.documentElement.lang = lang;
    document.querySelectorAll('[data-th][data-en]').forEach(function (el) {
      if (el.id === 'settings-theme-label') {
        return;
      }
      var text = lang === 'en' ? el.getAttribute('data-en') : el.getAttribute('data-th');
      if (!text) {
        return;
      }
      if (text.indexOf('<') !== -1) {
        el.innerHTML = text;
      } else {
        el.textContent = text;
      }
    });
    document.querySelectorAll('[data-th-placeholder]').forEach(function (el) {
      var ph = lang === 'en' ? el.getAttribute('data-en-placeholder') : el.getAttribute('data-th-placeholder');
      if (ph) {
        el.placeholder = ph;
      }
    });
    document.querySelectorAll('select option[data-th][data-en]').forEach(function (opt) {
      var label = lang === 'en' ? opt.getAttribute('data-en') : opt.getAttribute('data-th');
      if (label) {
        opt.textContent = label;
      }
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    try {
      var lang = localStorage.getItem('lang') || 'th';
      if (lang === 'en' || lang === 'th') {
        applyLanguage(lang);
      }
    } catch (err) {}
  });

  window.addEventListener('pageshow', function (event) {
    try {
      var theme = localStorage.getItem('theme') || 'light';
      document.documentElement.classList.toggle('dark', theme === 'dark');
      var lang = localStorage.getItem('lang') || 'th';
      if (lang === 'en' || lang === 'th') {
        applyLanguage(lang);
      }
    } catch (err) {}
  });
})();
</script>
