<?php
declare(strict_types=1);
?>
<?php if (($flashError ?? '') !== ''): ?>
  <div class="mt-4 rounded-xl border border-[#F3B5B5] bg-[#FFF3F3] px-4 py-2 text-sm text-[#9C1D1D]">
    <?= htmlspecialchars((string) $flashError, ENT_QUOTES, 'UTF-8') ?>
  </div>
<?php elseif (($flashSuccess ?? '') !== ''): ?>
  <div class="mt-4 rounded-xl border border-[#BFE3C6] bg-[#EDF8EF] px-4 py-2 text-sm text-[#1E6D2D]">
    <?= htmlspecialchars((string) $flashSuccess, ENT_QUOTES, 'UTF-8') ?>
  </div>
<?php endif; ?>
