<?php
declare(strict_types=1);
?>
<script>
(function () {
  try {
    var theme = localStorage.getItem('theme') || 'light';
    if (theme === 'dark') {
      document.documentElement.classList.add('dark');
    } else {
      document.documentElement.classList.remove('dark');
    }
    var savedLang = localStorage.getItem('lang') || 'th';
    if (savedLang === 'en' || savedLang === 'th') {
      document.documentElement.lang = savedLang;
    }
  } catch (err) {}
})();
</script>
