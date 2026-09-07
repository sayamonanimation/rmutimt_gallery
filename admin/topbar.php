<?php
declare(strict_types=1);

$title = isset($pageTitle) ? (string) $pageTitle : '';
$subtitle = isset($pageSubtitle) ? (string) $pageSubtitle : '';
$actionId = isset($actionButtonId) ? (string) $actionButtonId : '';
$actionLabel = isset($actionButtonLabel) ? (string) $actionButtonLabel : '';
$actionIcon = isset($actionButtonIconSvg) ? (string) $actionButtonIconSvg : '';
$actionClass = isset($actionButtonClass) ? (string) $actionButtonClass : 'inline-flex items-center gap-2 rounded-xl bg-[var(--mt-red)] px-5 py-2.5 text-sm font-semibold text-white hover:bg-[var(--mt-red-dark)]';
$rightContentHtml = isset($topbarRightHtml) ? (string) $topbarRightHtml : '';
?>
<div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
  <div class="flex items-center gap-3">
    <!-- Hamburger button (mobile only) -->
    <button type="button" onclick="openSidebar()" class="lg:hidden inline-flex items-center justify-center rounded-lg border border-[var(--border)] bg-white p-2 text-gray-600 hover:bg-gray-50 hover:text-gray-900 dark:bg-[#1e1e1e] dark:border-[#333333] dark:text-gray-300 dark:hover:bg-gray-800 dark:hover:text-white" aria-label="Open menu">
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
    </button>
    <div>
      <h1 class="font-display text-4xl leading-none dark:text-gray-100"<?php if (!empty($pageTitleDataTh) && !empty($pageTitleDataEn)): ?> data-th="<?= htmlspecialchars((string) $pageTitleDataTh, ENT_QUOTES, 'UTF-8') ?>" data-en="<?= htmlspecialchars((string) $pageTitleDataEn, ENT_QUOTES, 'UTF-8') ?>"<?php endif; ?>><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
      <?php if ($subtitle !== ''): ?>
        <p class="mt-2 text-sm text-[var(--text-muted)] dark:text-gray-400"<?php if (!empty($pageSubtitleDataTh) && !empty($pageSubtitleDataEn)): ?> data-th="<?= htmlspecialchars((string) $pageSubtitleDataTh, ENT_QUOTES, 'UTF-8') ?>" data-en="<?= htmlspecialchars((string) $pageSubtitleDataEn, ENT_QUOTES, 'UTF-8') ?>"<?php endif; ?>><?= htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8') ?></p>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($actionLabel !== ''): ?>
    <button type="button" <?= $actionId !== '' ? 'id="' . htmlspecialchars($actionId, ENT_QUOTES, 'UTF-8') . '"' : '' ?> class="<?= htmlspecialchars($actionClass, ENT_QUOTES, 'UTF-8') ?>">
      <?= $actionIcon ?>
      <span<?php if (!empty($actionButtonLabelDataTh) && !empty($actionButtonLabelDataEn)): ?> data-th="<?= htmlspecialchars((string) $actionButtonLabelDataTh, ENT_QUOTES, 'UTF-8') ?>" data-en="<?= htmlspecialchars((string) $actionButtonLabelDataEn, ENT_QUOTES, 'UTF-8') ?>"<?php endif; ?>><?= htmlspecialchars($actionLabel, ENT_QUOTES, 'UTF-8') ?></span>
    </button>
  <?php elseif ($rightContentHtml !== ''): ?>
    <?= $rightContentHtml ?>
  <?php endif; ?>
</div>

<script>
function openSidebar() {
  var sidebar = document.getElementById('admin-sidebar');
  var overlay = document.getElementById('sidebar-overlay');
  if (sidebar) {
    sidebar.classList.remove('-translate-x-full');
    sidebar.classList.add('translate-x-0');
  }
  if (overlay) overlay.classList.remove('hidden');
  document.body.classList.add('overflow-hidden', 'lg:overflow-auto');
}
function closeSidebar() {
  var sidebar = document.getElementById('admin-sidebar');
  var overlay = document.getElementById('sidebar-overlay');
  if (sidebar) {
    sidebar.classList.add('-translate-x-full');
    sidebar.classList.remove('translate-x-0');
  }
  if (overlay) overlay.classList.add('hidden');
  document.body.classList.remove('overflow-hidden', 'lg:overflow-auto');
}
</script>
