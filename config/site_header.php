<?php
declare(strict_types=1);

// Ensure required data variables exist with safe defaults
$years = $years ?? [];
$categories = $categories ?? [];
$advisors = $advisors ?? [];
$searchQuery = $searchQuery ?? '';
$searchYear = $searchYear ?? '';
$selectedCategoryIds = $selectedCategoryIds ?? [];
$searchAdvisor = $searchAdvisor ?? '';
$sortOrder = $sortOrder ?? 'newest';
$isLoggedIn = $isLoggedIn ?? false;
$dashboardLink = $dashboardLink ?? './student/dashboard.php';
$displayAvatarUrl = $displayAvatarUrl ?? '';
$displayAvatarText = $displayAvatarText ?? 'U';
$displayName = $displayName ?? '';
$assetBase = $assetBase ?? '.';
$activePage = $activePage ?? 'index';
?>

<style>
  /* Search Bar Master Styles */
  .searchbar-grid {
    position: relative;
    z-index: 100;
    overflow: visible;
  }
  @media (min-width: 768px) {
    .searchbar-grid {
      display: flex;
      flex-direction: row;
      align-items: stretch;
      gap: 0;
    }
    .searchbar-grid > .search-field--grow {
      flex: 1 1 auto;
      min-width: 0;
    }
    .searchbar-grid > .search-field--filter {
      flex: 0 0 auto;
      width: auto;
      min-width: 7.25rem;
      max-width: 9.5rem;
    }
    .searchbar-grid > .search-field--divider {
      border-right: 1px solid #e5e7eb;
    }
    html.dark .searchbar-grid > .search-field--divider {
      border-right-color: #374151;
    }
    .searchbar-grid > .search-btn-wrap {
      flex: 0 0 auto;
      display: flex;
      align-items: center;
      padding: 0 4px 0 6px;
    }
  }

  .search-field {
    display: flex;
    align-items: center;
    gap: 8px;
    height: 42px;
    padding: 0 14px;
    background: transparent !important;
    border: none !important;
    border-radius: 0.75rem;
    font-size: 13px;
    color: var(--text-primary);
    transition: background 0.2s ease, border-radius 0.2s ease;
  }

  .search-field:focus-within,
  html.dark .search-field:focus-within {
    background: transparent !important;
    border: none !important;
    box-shadow: none !important;
  }

  .search-field.is-active {
    background: #F3F4F6 !important;
    border-radius: 12px !important;
  }
  .search-field.is-active .selected-box,
  .search-field.is-active select {
    color: #111827 !important;
  }
  .search-field.is-active .select-wrap svg {
    color: #4B5563 !important;
  }

  html.dark .search-field.is-active {
    background: #262626 !important;
    border-radius: 12px !important;
  }
  html.dark .search-field.is-active .selected-box,
  html.dark .search-field.is-active select {
    color: #F3F4F6 !important;
  }
  html.dark .search-field.is-active .select-wrap svg {
    color: #9CA3AF !important;
  }

  /* Input & Select Box Normalization - Remove white default boxes */
  .search-field input:not([type="checkbox"]),
  .search-field select {
    width: 100%;
    height: 42px;
    border: 0 !important;
    background: transparent !important;
    font-size: 13px;
    color: var(--text-primary);
    outline: none !important;
    box-shadow: none !important;
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
  }
  .search-field input::placeholder {
    color: var(--text-muted);
  }

  /* Hide native select completely when custom select is active */
  .search-field .select-wrap select.native-select-hidden {
    position: absolute !important;
    width: 0 !important;
    height: 0 !important;
    padding: 0 !important;
    margin: 0 !important;
    overflow: hidden !important;
    opacity: 0 !important;
    pointer-events: none !important;
    border: 0 !important;
  }

  .search-field .select-wrap {
    width: 100%;
    position: relative;
    height: 42px;
    display: flex;
    align-items: center;
  }

  .search-field .select-wrap .selected-box {
    border: 0 !important;
    background: transparent !important;
    cursor: pointer;
    font: inherit;
    font-size: 13px;
    text-align: left;
    height: 42px;
    width: 100%;
    outline: none !important;
  }

  .search-field .select-wrap svg {
    position: absolute !important;
    right: 10px !important;
    top: 50% !important;
    transform: translateY(-50%) rotate(0deg);
    pointer-events: none !important;
    color: #9CA3AF !important;
    z-index: 20 !important;
    transition: transform 0.2s ease !important;
    width: 14px !important;
    height: 14px !important;
  }

  .options-menu {
    display: flex !important;
    flex-direction: column !important;
    z-index: 10050 !important;
    border-radius: 0.75rem !important;
    padding: 4px 0 !important;
    width: max-content !important;
    min-width: 100% !important;
    max-width: 280px !important;
    max-height: 260px !important;
    overflow-y: auto !important;
    overscroll-behavior: contain !important;
  }

  .options-menu .option-item {
    display: block !important;
    width: 100% !important;
    text-align: left !important;
    white-space: nowrap !important;
    overflow: hidden !important;
    text-overflow: ellipsis !important;
    box-sizing: border-box !important;
    padding: 8px 14px !important;
    font-size: 12.5px !important;
    line-height: 1.4 !important;
  }

  .search-field .select-wrap .selected-box span {
    display: block;
    width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 13px;
  }

  html.dark .searchbar-grid {
    background-color: #1e1e1e !important;
    border-color: #333333 !important;
  }
  html.dark .search-field input:not([type="checkbox"]),
  html.dark .search-field select,
  html.dark .search-field .selected-box {
    color: #F3F4F6 !important;
  }
  html.dark .search-field input::placeholder {
    color: #9CA3AF !important;
  }
  html.dark .options-menu {
    background-color: #1e1e1e !important;
    border-color: #333333 !important;
    color: #E5E7EB !important;
    box-shadow: 0 12px 30px rgba(0, 0, 0, 0.5) !important;
  }
</style>

  <header id="site-header" class="site-header sticky top-0 z-[1000] border-b border-[var(--border)] bg-white/95 backdrop-blur-md transition-[box-shadow,background-color] duration-200 dark:border-[#262626] dark:bg-[#0a0a0a]/95">
    <div class="mx-auto max-w-[1240px] px-6">
      <div class="site-header-inner flex items-center justify-between gap-4">
        <a href="./index.php" class="group flex shrink-0 items-center gap-2 sm:gap-3 no-underline transition-all duration-300">
          <img src="<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>/assets/images/mt-logo.png" alt="โลโก้สาขาเทคโนโลยีมัลติมีเดีย" class="h-8 w-8 sm:h-9 sm:w-9 transition-transform duration-500 ease-out group-hover:-translate-y-1 group-hover:scale-110 group-hover:rotate-6" />
          <div class="leading-tight">
            <div class="hidden sm:block text-[10px] font-medium tracking-[0.22em] text-[var(--text-muted)] transition-colors duration-300 group-hover:text-[var(--mt-red)]">RAJAMANGALA UNIVERSITY OF TECHNOLOGY ISAN</div>
            <div class="font-display text-base sm:text-lg lg:text-[21px] font-semibold leading-[1.05] text-[var(--text-primary)] transition-colors duration-300 group-hover:text-[var(--mt-red-dark)]">MT Thesis Gallery</div>
          </div>
        </a>

        <div class="flex items-center gap-2">
          <button
            type="button"
            id="btn-nav-search"
            class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-gray-700 transition-colors hover:text-[var(--mt-red)] dark:text-gray-300 dark:hover:text-white cursor-pointer"
            aria-label="ค้นหา"
            aria-expanded="false"
            aria-controls="nav-search-dropdown"
          >
            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
              <path fill-rule="evenodd" d="M9 3a6 6 0 104.472 10.03l2.249 2.25a.75.75 0 101.06-1.06l-2.25-2.249A6 6 0 009 3zm-4.5 6a4.5 4.5 0 119 0 4.5 4.5 0 01-9 0z" clip-rule="evenodd" />
            </svg>
          </button>

          <nav class="hidden md:flex items-center gap-6" aria-label="Main navigation">
            <a href="./index.php" class="text-sm font-medium <?= $activePage === 'index' ? 'text-[var(--mt-red)] dark:text-red-400 font-semibold' : 'text-gray-700 dark:text-gray-300 hover:text-[var(--mt-red)] dark:hover:text-white' ?> transition-colors" data-th="หน้าแรก" data-en="Home" <?= $activePage === 'index' ? 'aria-current="page"' : '' ?>>หน้าแรก</a>
            <a href="./about.php" class="text-sm font-medium <?= $activePage === 'about' ? 'text-[var(--mt-red)] dark:text-red-400 font-semibold' : 'text-gray-700 dark:text-gray-300 hover:text-[var(--mt-red)] dark:hover:text-white' ?> transition-colors" data-th="เกี่ยวกับเรา" data-en="About Us" <?= $activePage === 'about' ? 'aria-current="page"' : '' ?>>เกี่ยวกับเรา</a>
          </nav>

          <div class="hidden md:block h-5 w-[1px] bg-gray-300 dark:bg-gray-700 mx-2"></div>

          <div class="flex items-center gap-3">
          <?php if ($isLoggedIn): ?>
            <a href="<?= htmlspecialchars($dashboardLink, ENT_QUOTES, 'UTF-8') ?>" class="group flex items-center gap-2 sm:gap-2.5 text-xs sm:text-sm font-semibold text-[#111827] dark:text-gray-100 no-underline transition-colors duration-200 hover:text-[#D32F2F] dark:hover:text-[#D32F2F]">
              <?php if ($displayAvatarUrl !== ''): ?>
                <img src="<?= htmlspecialchars($displayAvatarUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Avatar" class="h-8 w-8 sm:h-10 sm:w-10 rounded-full border-2 border-[#DADDE1] object-cover transition-all duration-200 group-hover:border-[#D32F2F]" />
              <?php else: ?>
                <div class="grid h-8 w-8 sm:h-10 sm:w-10 place-items-center overflow-hidden rounded-full border-2 border-[#DADDE1] bg-[#E8ECEF] text-xs sm:text-sm font-semibold text-[#555E66] transition-all duration-200 group-hover:border-[#D32F2F]">
                  <?= htmlspecialchars($displayAvatarText, ENT_QUOTES, 'UTF-8') ?>
                </div>
              <?php endif; ?>
              <span class="truncate max-w-[80px] sm:max-w-[150px] md:max-w-[200px] text-xs sm:text-sm font-medium text-[#111827] dark:text-gray-100 transition-colors duration-200 group-hover:text-[#D32F2F]">
                <?= htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8') ?>
              </span>
            </a>
          <?php else: ?>
            <button type="button" id="btn-open-signup" class="inline-flex items-center gap-1.5 rounded-xl border border-gray-300 dark:border-gray-700 bg-transparent px-3 py-1.5 sm:px-4 sm:py-2 text-[10px] sm:text-xs font-semibold tracking-wide text-gray-700 dark:text-gray-200 transition-all duration-200 hover:border-[#D32F2F]/60 hover:text-[#D32F2F] dark:hover:border-red-500/60 dark:hover:text-red-400 cursor-pointer">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 sm:h-4 sm:w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path d="M10 10a4 4 0 100-8 4 4 0 000 8z" />
                <path fill-rule="evenodd" d="M.458 16.042C1.732 13.133 4.522 11 10 11s8.268 2.133 9.542 5.042A1 1 0 0118.63 17H1.37a1 1 0 01-.912-1.458z" clip-rule="evenodd" />
              </svg>
              <span data-th="สมัครสมาชิก" data-en="SIGN UP">สมัครสมาชิก</span>
            </button>
            <button type="button" id="btn-open-signin" class="inline-flex items-center justify-center rounded-xl bg-[#D32F2F] px-3.5 py-1.5 sm:px-4 sm:py-2 text-[10px] sm:text-xs font-bold tracking-widest text-white shadow-[0_4px_14px_rgba(211,47,47,0.3)] transition-all duration-200 hover:bg-[#B71C1C] hover:shadow-[0_6px_18px_rgba(211,47,47,0.45)] hover:-translate-y-0.5 cursor-pointer" data-th="เข้าสู่ระบบ" data-en="SIGN IN">
              เข้าสู่ระบบ
            </button>
          <?php endif; ?>

          <div class="relative" id="site-settings-wrap">
            <button
              type="button"
              id="btn-site-settings"
              aria-label="ตั้งค่าการแสดงผล"
              aria-expanded="false"
              aria-haspopup="true"
              class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-[var(--border)] bg-[var(--bg-primary)] text-[var(--text-primary)] transition-colors duration-200 hover:border-[var(--mt-red)] hover:text-[var(--mt-red)] focus:outline-none focus:ring-2 focus:ring-[var(--mt-red)]/30"
            >
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
              </svg>
            </button>
            <div
              id="site-settings-dropdown"
              class="invisible absolute right-0 top-[calc(100%+8px)] z-[1100] min-w-[220px] translate-y-[-6px] rounded-xl border border-[var(--border)] bg-[var(--bg-primary)] p-1.5 opacity-0 shadow-[0_12px_30px_var(--shadow)] transition-all duration-200 dark:border-[#262626] dark:bg-[#1e1e1e]"
              role="menu"
              aria-hidden="true"
            >
              <button type="button" id="btn-toggle-lang" class="flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm font-medium text-[var(--text-primary)] transition-colors hover:bg-[#FFF1F1] hover:text-[var(--mt-red)] dark:hover:bg-[#262626]" role="menuitem">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9 9 0 100-18 9 9 0 000 18z" />
                  <path stroke-linecap="round" stroke-linejoin="round" d="M3.6 9h16.8M3.6 15h16.8M12 3c2.2 2.6 2.2 15.4 0 18M12 3c-2.2 2.6-2.2 15.4 0 18" />
                </svg>
                <span id="settings-lang-label" data-th="English" data-en="ภาษาไทย">English</span>
              </button>
              <button type="button" id="btn-toggle-theme" class="mt-0.5 flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left text-sm font-medium text-[var(--text-primary)] transition-colors hover:bg-[#FFF1F1] hover:text-[var(--mt-red)] dark:hover:bg-[#262626]" role="menuitem">
                <svg id="settings-theme-icon" xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                  <path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1111.21 3a7 7 0 009.79 9.79z" />
                </svg>
                <span id="settings-theme-label">โหมดมืด</span>
              </button>
            </div>
          </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Search Dropdown Bar -->
    <div
      id="nav-search-dropdown"
      class="invisible absolute left-0 right-0 top-full z-[1050] translate-y-[-6px] bg-transparent opacity-0 transition-all duration-200 overflow-visible"
      aria-hidden="true"
    >
      <div class="mx-auto max-w-[850px] px-4 py-2 overflow-visible">
        <form id="nav-search-form" method="get" action="./index.php" novalidate class="relative overflow-visible">
            <input type="hidden" name="sort" value="<?= htmlspecialchars(($sortOrder === 'oldest') ? 'oldest' : 'newest', ENT_QUOTES, 'UTF-8') ?>" />
            <input type="hidden" id="categories-input" name="categories" value="<?= htmlspecialchars(implode(',', $selectedCategoryIds), ENT_QUOTES, 'UTF-8') ?>" />
            <div class="searchbar-grid flex flex-col md:flex-row md:items-center w-full gap-3 md:gap-0 p-2 bg-white dark:bg-[#1e1e1e] border border-[#E0E0E0] dark:border-[#333] rounded-2xl md:rounded-full shadow-md overflow-visible relative">
              <div class="search-field w-full md:flex-1 bg-transparent">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5 shrink-0" style="color:var(--text-muted);" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                  <path fill-rule="evenodd" d="M9 3a6 6 0 104.472 10.03l2.249 2.25a.75.75 0 101.06-1.06l-2.25-2.249A6 6 0 009 3zm-4.5 6a4.5 4.5 0 119 0 4.5 4.5 0 01-9 0z" clip-rule="evenodd" />
                </svg>
                <label for="q" class="sr-only">ค้นหา</label>
                <input id="q" name="q" type="search" autocomplete="off" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>" placeholder="ค้นหาชื่อวิทยานิพนธ์ คำสำคัญ หรือชื่อนักศึกษา..." data-th-placeholder="ค้นหาชื่อวิทยานิพนธ์ คำสำคัญ หรือชื่อนักศึกษา..." data-en-placeholder="Search thesis title, keyword, or student name..." />
              </div>

              <div class="search-field w-full md:w-[160px] shrink-0 md:border-r border-[#E0E0E0] dark:border-[#333] bg-transparent">
                <div class="select-wrap">
                  <label for="year" class="sr-only">ปีการศึกษา</label>
                  <select id="year" name="year">
                    <option value="" data-th="ปีการศึกษา" data-en="Year">ปีการศึกษา</option>
                    <?php foreach ($years as $y): ?>
                      <option value="<?= htmlspecialchars($y['year'], ENT_QUOTES, 'UTF-8') ?>" <?= $searchYear === $y['year'] ? 'selected' : '' ?>><?= htmlspecialchars($y['year'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.25a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd" />
                  </svg>
                </div>
              </div>

              <div class="search-field w-full md:w-[160px] shrink-0 md:border-r border-[#E0E0E0] dark:border-[#333] bg-transparent">
                <div class="select-wrap">
                  <label for="category" class="sr-only">หมวดหมู่</label>
                  <select id="category" name="category">
                    <option value="" data-th="หมวดหมู่" data-en="Category">หมวดหมู่</option>
                    <?php foreach ($categories as $cat): ?>
                      <?php
                      $catIdInt = (int) $cat['id'];
                      $isCatSelected = in_array($catIdInt, $selectedCategoryIds, true);
                      ?>
                      <option value="<?= htmlspecialchars((string) $cat['id'], ENT_QUOTES, 'UTF-8') ?>" <?= $isCatSelected ? 'selected' : '' ?>><?= htmlspecialchars($cat['name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.25a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd" />
                  </svg>
                </div>
              </div>

              <div class="search-field w-full md:w-[160px] shrink-0 bg-transparent">
                <div class="select-wrap">
                  <label for="advisor" class="sr-only">อาจารย์ที่ปรึกษา</label>
                  <select id="advisor" name="advisor">
                    <option value="" data-th="อาจารย์ที่ปรึกษา" data-en="Advisor">อาจารย์ที่ปรึกษา</option>
                    <?php foreach ($advisors as $adv): ?>
                      <option value="<?= htmlspecialchars((string) $adv['id'], ENT_QUOTES, 'UTF-8') ?>" <?= $searchAdvisor === (string) $adv['id'] ? 'selected' : '' ?>><?= htmlspecialchars($adv['prefix'] . ' ' . $adv['full_name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                  <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.25a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd" />
                  </svg>
                </div>
              </div>

              <div class="search-btn-wrap w-full md:w-auto shrink-0 md:pl-2">
                <button type="submit" class="btn-search h-[42px] rounded-xl md:rounded-full px-6 font-semibold transition-all duration-200 hover:-translate-y-0.5 hover:shadow-lg" data-th="ค้นหา" data-en="SEARCH">ค้นหา</button>
              </div>
            </div>
          </form>
      </div>
    </div>
  </header>

  <!-- Theme, Language & Custom Select Control Scripts -->
  <script>
    (function () {
      var MOON_SVG = '<path stroke-linecap="round" stroke-linejoin="round" d="M21 12.79A9 9 0 1111.21 3a7 7 0 009.79 9.79z" />';
      var SUN_SVG = '<path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2m0 14v2M4.22 4.22l1.42 1.42m12.72 12.72l1.42 1.42M3 12h2m14 0h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42M12 8a4 4 0 100 8 4 4 0 000-8z" />';

      function getLang() {
        var lang = localStorage.getItem('lang') || 'th';
        return lang === 'en' ? 'en' : 'th';
      }

      function isDarkTheme() {
        return document.documentElement.classList.contains('dark');
      }

      function syncCustomSelectLabels(lang) {
        document.querySelectorAll('.select-wrap').forEach(function (wrap) {
          var select = wrap.querySelector('select');
          if (!select) {
            return;
          }
          var selectedText = wrap.querySelector('.selected-box span');
          var selectedOpt = select.options[select.selectedIndex];
          if (selectedText) {
            if (selectedText.hasAttribute('data-th')) {
              selectedText.textContent = lang === 'en' ? selectedText.getAttribute('data-en') : selectedText.getAttribute('data-th');
            } else if (selectedOpt && selectedOpt.hasAttribute('data-th')) {
              selectedText.textContent = lang === 'en' ? selectedOpt.getAttribute('data-en') : selectedOpt.getAttribute('data-th');
            } else if (selectedOpt) {
              selectedText.textContent = selectedOpt.textContent;
            }
          }
          wrap.querySelectorAll('.option-item').forEach(function (item, index) {
            var opt = select.options[index];
            if (!opt) {
              return;
            }
            if (opt.hasAttribute('data-th')) {
              item.textContent = lang === 'en' ? opt.getAttribute('data-en') : opt.getAttribute('data-th');
            } else {
              item.textContent = opt.textContent;
            }
          });
        });
      }

      function applyLanguage(lang) {
        lang = lang === 'en' ? 'en' : 'th';
        document.documentElement.lang = lang === 'en' ? 'en' : 'th';

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

        syncCustomSelectLabels(lang);
      }

      function updateThemeUI() {
        var lang = getLang();
        var isDark = isDarkTheme();
        var label = document.getElementById('settings-theme-label');
        var icon = document.getElementById('settings-theme-icon');

        if (label) {
          if (isDark) {
            label.textContent = lang === 'en' ? 'Light mode' : 'โหมดสว่าง';
          } else {
            label.textContent = lang === 'en' ? 'Dark mode' : 'โหมดมืด';
          }
        }
        if (icon) {
          icon.innerHTML = isDark ? SUN_SVG : MOON_SVG;
        }
      }

      function setTheme(theme) {
        var isDark = theme === 'dark';
        document.documentElement.classList.toggle('dark', isDark);
        localStorage.setItem('theme', isDark ? 'dark' : 'light');
        updateThemeUI();
      }

      function toggleTheme() {
        setTheme(isDarkTheme() ? 'light' : 'dark');
      }

      function toggleLang() {
        var next = getLang() === 'en' ? 'th' : 'en';
        localStorage.setItem('lang', next);
        applyLanguage(next);
        updateThemeUI();
      }

      function openSettingsDropdown() {
        var btn = document.getElementById('btn-site-settings');
        var menu = document.getElementById('site-settings-dropdown');
        if (!btn || !menu) {
          return;
        }
        menu.classList.remove('invisible', 'opacity-0', 'translate-y-[-6px]');
        menu.classList.add('opacity-100', 'translate-y-0');
        menu.setAttribute('aria-hidden', 'false');
        btn.setAttribute('aria-expanded', 'true');
      }

      function closeSettingsDropdown() {
        var btn = document.getElementById('btn-site-settings');
        var menu = document.getElementById('site-settings-dropdown');
        if (!btn || !menu) {
          return;
        }
        menu.classList.add('invisible', 'opacity-0', 'translate-y-[-6px]');
        menu.classList.remove('opacity-100', 'translate-y-0');
        menu.setAttribute('aria-hidden', 'true');
        btn.setAttribute('aria-expanded', 'false');
      }

      function isSettingsDropdownOpen() {
        var menu = document.getElementById('site-settings-dropdown');
        return menu && !menu.classList.contains('invisible');
      }

      document.addEventListener('DOMContentLoaded', function () {
        applyLanguage(getLang());
        updateThemeUI();

        var btnSettings = document.getElementById('btn-site-settings');
        var btnLang = document.getElementById('btn-toggle-lang');
        var btnTheme = document.getElementById('btn-toggle-theme');
        var wrap = document.getElementById('site-settings-wrap');

        if (btnSettings) {
          btnSettings.addEventListener('click', function (e) {
            e.stopPropagation();
            if (isSettingsDropdownOpen()) {
              closeSettingsDropdown();
            } else {
              openSettingsDropdown();
            }
          });
        }

        if (btnLang) {
          btnLang.addEventListener('click', function (e) {
            e.stopPropagation();
            toggleLang();
            closeSettingsDropdown();
          });
        }

        if (btnTheme) {
          btnTheme.addEventListener('click', function (e) {
            e.stopPropagation();
            toggleTheme();
            closeSettingsDropdown();
          });
        }

        document.addEventListener('click', function (e) {
          if (!wrap || !isSettingsDropdownOpen()) {
            return;
          }
          if (!wrap.contains(e.target)) {
            closeSettingsDropdown();
          }
        });

        document.addEventListener('keydown', function (e) {
          if (e.key === 'Escape' && isSettingsDropdownOpen()) {
            closeSettingsDropdown();
          }
        });
      });

      window.addEventListener('pageshow', function (event) {
        var theme = localStorage.getItem('theme');
        document.documentElement.classList.toggle('dark', theme === 'dark');
        applyLanguage(getLang());
        updateThemeUI();
      });
    })();
  </script>

  <!-- Nav Search Dropdown Toggle Script -->
  <script>
    (function () {
      function closeSettingsMenu() {
        var settingsBtn = document.getElementById('btn-site-settings');
        var settingsMenu = document.getElementById('site-settings-dropdown');
        if (!settingsBtn || !settingsMenu) {
          return;
        }
        settingsMenu.classList.add('invisible', 'opacity-0', 'translate-y-[-6px]');
        settingsMenu.classList.remove('opacity-100', 'translate-y-0');
        settingsMenu.setAttribute('aria-hidden', 'true');
        settingsBtn.setAttribute('aria-expanded', 'false');
      }

      function isNavSearchOpen() {
        var panel = document.getElementById('nav-search-dropdown');
        return panel && !panel.classList.contains('invisible');
      }

      function setNavSearchButtonState(isOpen) {
        var btn = document.getElementById('btn-nav-search');
        if (!btn) {
          return;
        }
        if (isOpen) {
          btn.classList.add('text-[var(--mt-red)]', 'dark:text-red-400');
          btn.classList.remove('text-gray-700', 'dark:text-gray-300');
        } else {
          btn.classList.remove('text-[var(--mt-red)]', 'dark:text-red-400');
          btn.classList.add('text-gray-700', 'dark:text-gray-300');
        }
      }

      function openNavSearch() {
        var panel = document.getElementById('nav-search-dropdown');
        var btn = document.getElementById('btn-nav-search');
        if (!panel || !btn) {
          return;
        }
        closeSettingsMenu();
        panel.classList.remove('invisible', 'opacity-0', 'translate-y-[-6px]');
        panel.classList.add('opacity-100', 'translate-y-0');
        panel.setAttribute('aria-hidden', 'false');
        btn.setAttribute('aria-expanded', 'true');
        setNavSearchButtonState(true);
      }

      function closeNavSearch() {
        var panel = document.getElementById('nav-search-dropdown');
        var btn = document.getElementById('btn-nav-search');
        if (!panel || !btn) {
          return;
        }
        panel.classList.add('invisible', 'opacity-0', 'translate-y-[-6px]');
        panel.classList.remove('opacity-100', 'translate-y-0');
        panel.setAttribute('aria-hidden', 'true');
        btn.setAttribute('aria-expanded', 'false');
        setNavSearchButtonState(false);
      }

      document.addEventListener('DOMContentLoaded', function () {
        var btn = document.getElementById('btn-nav-search');
        var panel = document.getElementById('nav-search-dropdown');
        var settingsBtn = document.getElementById('btn-site-settings');

        if (btn && panel) {
          btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (isNavSearchOpen()) {
              closeNavSearch();
            } else {
              openNavSearch();
            }
          });

          document.addEventListener('click', function (e) {
            if (!isNavSearchOpen()) {
              return;
            }
            if (!panel.contains(e.target) && !btn.contains(e.target)) {
              closeNavSearch();
            }
          });

          document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && isNavSearchOpen()) {
              closeNavSearch();
            }
          });
        }

        if (settingsBtn) {
          settingsBtn.addEventListener('click', function () {
            if (isNavSearchOpen()) {
              closeNavSearch();
            }
          });
        }
      });
    })();
  </script>

  <!-- Custom Select Enhancer Script -->
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const selectWraps = document.querySelectorAll('.select-wrap');
      
      selectWraps.forEach(wrap => {
        const select = wrap.querySelector('select');
        if (!select) return;
        
        select.classList.add('native-select-hidden');
        select.tabIndex = -1;

        if (select.id === 'category') {
          // Multi-Select Checkbox Dropdown for Category Filter
          const customSelect = document.createElement('div');
          customSelect.className = 'custom-select relative w-full h-full cursor-pointer';

          const selectedBox = document.createElement('button');
          selectedBox.type = 'button';
          selectedBox.className = 'selected-box flex items-center justify-between h-full w-full bg-transparent text-[13px] font-medium text-[#444] dark:text-[#E5E7EB] transition-colors hover:text-[var(--mt-red)] outline-none pl-3 pr-8';
          
          const selectedText = document.createElement('span');
          selectedText.className = 'truncate';
          selectedBox.appendChild(selectedText);

          const optionsMenu = document.createElement('div');
          optionsMenu.className = 'options-menu absolute left-0 top-[calc(100%+8px)] w-max min-w-[200px] max-w-[280px] bg-white dark:bg-[#1e1e1e] border border-[#E0E0E0] dark:border-[#333] rounded-xl shadow-[0_12px_30px_rgba(0,0,0,0.15)] z-[100] opacity-0 invisible translate-y-[-10px] transition-all duration-200 ease-out overflow-hidden p-1.5';

          // Toolbar (Clean Header with Clear Button)
          const isEn = (document.documentElement.lang === 'en' || document.documentElement.getAttribute('lang') === 'en');
          const toolbar = document.createElement('div');
          toolbar.className = 'flex items-center justify-between px-3 py-1.5 border-b border-gray-100 dark:border-[#262626] mb-1 text-[11px] font-semibold text-gray-500 dark:text-gray-400 select-none';
          toolbar.innerHTML = `
            <span data-th="เลือกหมวดหมู่" data-en="Select Categories">${isEn ? 'Select Categories' : 'เลือกหมวดหมู่'}</span>
            <button type="button" class="hover:text-rose-500 transition-colors btn-clear-all" data-th="ล้างการเลือก" data-en="Clear All">${isEn ? 'Clear All' : 'ล้างการเลือก'}</button>
          `;
          optionsMenu.appendChild(toolbar);

          const itemsContainer = document.createElement('div');
          itemsContainer.className = 'max-h-[220px] overflow-y-auto space-y-0.5 custom-scrollbar';

          function updateMultiCategoryDisplay() {
            const checkedBoxes = itemsContainer.querySelectorAll('input[type="checkbox"]:checked');
            const hiddenInput = document.getElementById('categories-input') || document.querySelector('input[name="categories"]');
            const checkedValues = Array.from(checkedBoxes).map(cb => cb.value);

            if (hiddenInput) {
              hiddenInput.value = checkedValues.join(',');
            }

            const currEn = (document.documentElement.lang === 'en' || document.documentElement.getAttribute('lang') === 'en');
            if (checkedBoxes.length === 0) {
              selectedText.textContent = currEn ? 'Category' : 'หมวดหมู่';
              selectedText.setAttribute('data-th', 'หมวดหมู่');
              selectedText.setAttribute('data-en', 'Category');
              selectedText.classList.remove('text-[var(--mt-red)]', 'dark:text-[#F87171]', 'font-semibold');
            } else if (checkedBoxes.length === 1) {
              selectedText.textContent = checkedBoxes[0].dataset.name;
              selectedText.removeAttribute('data-th');
              selectedText.removeAttribute('data-en');
              selectedText.classList.add('text-[var(--mt-red)]', 'dark:text-[#F87171]', 'font-semibold');
            } else {
              const thText = `หมวดหมู่ (${checkedBoxes.length})`;
              const enText = `Category (${checkedBoxes.length})`;
              selectedText.textContent = currEn ? enText : thText;
              selectedText.setAttribute('data-th', thText);
              selectedText.setAttribute('data-en', enText);
              selectedText.classList.add('text-[var(--mt-red)]', 'dark:text-[#F87171]', 'font-semibold');
            }
          }

          Array.from(select.options).forEach((option) => {
            if (!option.value) return;
            const row = document.createElement('label');
            row.className = 'flex items-center gap-2.5 px-3 py-2 text-[12px] text-[#444] dark:text-[#E5E7EB] hover:bg-[#FFF1F1] dark:hover:bg-[#262626] rounded-lg cursor-pointer transition-colors select-none w-full';

            const checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.value = option.value;
            checkbox.dataset.name = option.text;
            checkbox.checked = option.selected;

            const labelText = document.createElement('span');
            labelText.className = 'truncate text-xs font-medium text-[#333] dark:text-gray-200 flex-1 min-w-0 text-left';
            labelText.textContent = option.text;

            checkbox.addEventListener('change', updateMultiCategoryDisplay);

            row.appendChild(checkbox);
            row.appendChild(labelText);
            itemsContainer.appendChild(row);
          });

          optionsMenu.appendChild(itemsContainer);

          toolbar.querySelector('.btn-clear-all').addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            itemsContainer.querySelectorAll('input[type="checkbox"]').forEach(cb => cb.checked = false);
            updateMultiCategoryDisplay();
          });

          customSelect.appendChild(selectedBox);
          customSelect.appendChild(optionsMenu);
          wrap.appendChild(customSelect);

          selectedBox.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            const isOpen = !optionsMenu.classList.contains('invisible');
            closeAllSelect();
            if (!isOpen) {
              const livePopover = document.getElementById('live-search-suggestions');
              if (livePopover) livePopover.classList.add('hidden');

              optionsMenu.classList.remove('invisible', 'opacity-0', 'translate-y-[-10px]');
              optionsMenu.classList.add('opacity-100', 'translate-y-0');
              const parentField = wrap.closest('.search-field');
              if (parentField) {
                parentField.style.zIndex = '10050';
                parentField.classList.add('is-active', 'bg-gray-100', 'dark:bg-[#262626]', 'rounded-xl');
              }

              const icon = wrap.querySelector('svg');
              if (icon) icon.style.transform = 'translateY(-50%) rotate(180deg)';
            }
          });

          updateMultiCategoryDisplay();
          return;
        }

        // Standard Single Select Custom UI
        const customSelect = document.createElement('div');
        customSelect.className = 'custom-select relative w-full h-full cursor-pointer';
        
        const selectedBox = document.createElement('button');
        selectedBox.type = 'button';
        selectedBox.className = 'selected-box flex items-center justify-between h-full w-full bg-transparent text-[13px] font-medium text-[#444] dark:text-[#E5E7EB] transition-colors hover:text-[var(--mt-red)] outline-none pl-3 pr-8';
        const selectedText = document.createElement('span');
        selectedText.className = 'truncate block w-full text-left';
        selectedText.textContent = select.options[select.selectedIndex].text;
        selectedBox.appendChild(selectedText);
        
        const optionsMenu = document.createElement('div');
        optionsMenu.className = 'options-menu custom-scrollbar absolute left-0 top-[calc(100%+8px)] w-max min-w-full max-w-[280px] max-h-[260px] overflow-y-auto bg-white dark:bg-[#1e1e1e] border border-[#E0E0E0] dark:border-[#333] rounded-xl shadow-[0_12px_30px_rgba(0,0,0,0.15)] z-[100] opacity-0 invisible translate-y-[-10px] transition-all duration-200 ease-out p-1';
        
        Array.from(select.options).forEach((option, index) => {
          const optionItem = document.createElement('button');
          optionItem.type = 'button';
          optionItem.className = 'option-item px-4 py-2.5 text-[12px] text-left text-[#444] dark:text-[#E5E7EB] transition-colors hover:bg-[#FFF1F1] dark:hover:bg-[#262626] hover:text-[var(--mt-red)] dark:hover:text-[#F87171]';
          optionItem.textContent = option.text;
          
          if (index === select.selectedIndex) {
            optionItem.classList.add('bg-[#F9FAFB]', 'dark:bg-[#262626]', 'text-[var(--mt-red)]', 'dark:text-[#F87171]', 'font-semibold');
          }
          
          optionItem.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            select.selectedIndex = index;
            select.blur();
            selectedText.textContent = option.text;
            select.dispatchEvent(new Event('change', { bubbles: true }));

            optionsMenu.querySelectorAll('.option-item').forEach(item => item.classList.remove('bg-[#F9FAFB]', 'dark:bg-[#262626]', 'text-[var(--mt-red)]', 'dark:text-[#F87171]', 'font-semibold'));
            optionItem.classList.add('bg-[#F9FAFB]', 'dark:bg-[#262626]', 'text-[var(--mt-red)]', 'dark:text-[#F87171]', 'font-semibold');
            
            closeAllSelect();
          });
          
          optionsMenu.appendChild(optionItem);
        });
        
        customSelect.appendChild(selectedBox);
        customSelect.appendChild(optionsMenu);
        wrap.appendChild(customSelect);
        
        selectedBox.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation();
          const isOpen = !optionsMenu.classList.contains('invisible');
          closeAllSelect();
          if (!isOpen) {
            const livePopover = document.getElementById('live-search-suggestions');
            if (livePopover) livePopover.classList.add('hidden');

            optionsMenu.classList.remove('invisible', 'opacity-0', 'translate-y-[-10px]');
            optionsMenu.classList.add('opacity-100', 'translate-y-0');
            const parentField = wrap.closest('.search-field');
            if (parentField) {
              parentField.style.zIndex = '10050';
              parentField.classList.add('is-active', 'bg-gray-100', 'dark:bg-[#262626]', 'rounded-xl');
            }

            const icon = wrap.querySelector('svg');
            if (icon) icon.style.transform = 'translateY(-50%) rotate(180deg)';
          }
        });
      });
      
      function closeAllSelect() {
        document.querySelectorAll('.options-menu').forEach(menu => {
          menu.classList.remove('opacity-100', 'translate-y-0');
          menu.classList.add('invisible', 'opacity-0', 'translate-y-[-10px]');
          const parentField = menu.closest('.search-field');
          if (parentField) {
            parentField.style.zIndex = '';
            parentField.classList.remove('is-active', 'bg-[#262626]', 'bg-gray-100', 'dark:bg-[#262626]', 'rounded-xl');
          }
        });
        document.querySelectorAll('.select-wrap svg').forEach(icon => {
          icon.style.transform = 'translateY(-50%) rotate(0deg)';
        });
        document.querySelectorAll('.select-wrap select').forEach(select => {
          select.blur();
        });
      }
      
      document.addEventListener('click', closeAllSelect);

      const navSearchForm = document.getElementById('nav-search-form');
      if (navSearchForm) {
        navSearchForm.addEventListener('submit', function () {
          sessionStorage.setItem('savedScrollPos', String(window.scrollY));
        });
      }
    });
  </script>

  <!-- Live Search Auto-Suggest System -->
  <script>
  (function () {
    const assetBase = '<?= htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8') ?>';

    function initLiveSearch() {
      const searchInput = document.getElementById('q');
      const searchForm = document.getElementById('nav-search-form');
      if (!searchInput || !searchForm) return;

      const searchGrid = searchForm.querySelector('.searchbar-grid') || searchForm;
      searchGrid.classList.add('relative');

      let popover = document.getElementById('live-search-suggestions');
      if (!popover) {
        popover = document.createElement('div');
        popover.id = 'live-search-suggestions';
        popover.className = 'hidden absolute left-0 right-0 top-[calc(100%+8px)] z-[9999] w-full rounded-2xl bg-white dark:bg-[#1e1e1e] border border-[#E0E0E0] dark:border-[#333] shadow-[0_25px_60px_rgba(0,0,0,0.25)] dark:shadow-[0_25px_60px_rgba(0,0,0,0.7)] overflow-hidden transition-all duration-200';
        searchGrid.appendChild(popover);
      }

      let debounceTimer = null;

      function renderSuggestions(data, query) {
        if (!data || !data.success || !Array.isArray(data.suggestions) || data.suggestions.length === 0) {
          if (query.trim().length >= 2) {
            popover.innerHTML = `
              <div class="p-6 text-center text-xs text-gray-500 dark:text-gray-400">
                <svg class="mx-auto h-8 w-8 mb-2 text-gray-400 opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                ไม่พบผลงานที่ตรงกับคำว่า "<span class="font-semibold text-gray-700 dark:text-gray-200">${escapeHtml(query)}</span>"
              </div>
            `;
            popover.classList.remove('hidden');
          } else {
            popover.classList.add('hidden');
          }
          return;
        }

        let html = `
          <div class="px-4 py-2 bg-gray-50 dark:bg-[#262626] border-b border-gray-100 dark:border-[#333] flex items-center justify-between text-[11px] font-medium text-gray-500 dark:text-gray-400">
            <span>ผลการค้นหาแนะนำ (${data.total_matches} รายการ)</span>
            <span>กด ESC เพื่อปิด</span>
          </div>
          <div class="divide-y divide-gray-100 dark:divide-[#262626] max-h-[360px] overflow-y-auto">
        `;

        data.suggestions.forEach((item) => {
          const thumbHtml = item.thumbnail
            ? `<img src="${escapeHtml(item.thumbnail)}" alt="" class="w-full h-full object-cover group-hover:scale-105 transition-transform" />`
            : `<div class="w-full h-full flex items-center justify-center text-xs text-gray-400">🖼️</div>`;

          html += `
            <a href="${escapeHtml(item.link)}" class="suggestion-item flex items-center gap-3.5 px-4 py-3 hover:bg-[#FFF5F5] dark:hover:bg-[#262626] transition-colors group cursor-pointer">
              <div class="w-11 h-11 shrink-0 rounded-xl bg-gray-100 dark:bg-[#262626] overflow-hidden border border-black/5 dark:border-white/10 flex items-center justify-center">
                ${thumbHtml}
              </div>
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-0.5">
                  <span class="inline-block rounded-md bg-rose-50 dark:bg-rose-950/50 px-2 py-0.5 text-[10px] font-semibold text-[var(--mt-red)] dark:text-rose-400 border border-rose-100 dark:border-rose-900/40">${escapeHtml(item.category_name)}</span>
                </div>
                <h4 class="text-xs font-semibold text-[#111827] dark:text-[#F3F4F6] truncate group-hover:text-[var(--mt-red)] dark:group-hover:text-rose-400 transition-colors">
                  ${escapeHtml(item.title_th)}
                </h4>
                ${item.title_en && item.title_en !== item.title_th ? `<p class="text-[11px] text-[#6B7280] dark:text-[#9CA3AF] truncate">${escapeHtml(item.title_en)}</p>` : ''}
              </div>
              <svg class="w-4 h-4 text-gray-400 group-hover:text-[var(--mt-red)] dark:group-hover:text-rose-400 transition-colors shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
              </svg>
            </a>
          `;
        });

        html += `
          </div>
          <button type="submit" form="nav-search-form" class="w-full text-center py-2.5 bg-gray-50 dark:bg-[#262626] text-xs font-semibold text-[var(--mt-red)] dark:text-rose-400 hover:bg-[#FFF1F1] dark:hover:bg-[#333] transition-colors border-t border-gray-100 dark:border-[#333] flex items-center justify-center gap-1.5">
            <span>ดูผลการค้นหาทั้งหมด (${data.total_matches} รายการ)</span>
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7-7 7M3 12h18" /></svg>
          </button>
        `;

        popover.innerHTML = html;
        popover.classList.remove('hidden');
      }

      function fetchSuggestions(query) {
        const q = (query !== undefined ? query : searchInput.value || '').trim();
        const year = document.getElementById('year')?.value || '';
        const category = document.getElementById('categories-input')?.value || document.getElementById('category')?.value || '';
        const advisor = document.getElementById('advisor')?.value || '';

        if (q.length < 2 && !year && !category && !advisor) {
          popover.classList.add('hidden');
          return;
        }

        const params = new URLSearchParams();
        if (q) params.set('q', q);
        if (year) params.set('year', year);
        if (category) params.set('category', category);
        if (advisor) params.set('advisor', advisor);

        fetch(assetBase + '/api/search_suggestions.php?' + params.toString())
          .then(res => res.json())
          .then(data => {
            renderSuggestions(data, q);
          })
          .catch(() => {
            popover.classList.add('hidden');
          });
      }

      function escapeHtml(str) {
        if (!str) return '';
        return str.replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]));
      }

      ['input', 'keyup', 'focus'].forEach(evtName => {
        searchInput.addEventListener(evtName, function () {
          if (typeof closeAllSelect === 'function') closeAllSelect();
          clearTimeout(debounceTimer);
          const q = this.value;
          debounceTimer = setTimeout(() => {
            fetchSuggestions(q);
          }, 150);
        });
      });

      ['year', 'category', 'advisor'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
          el.addEventListener('change', function () {
            fetchSuggestions(searchInput.value);
          });
        }
      });

      document.addEventListener('click', function (e) {
        if (!searchGrid.contains(e.target) && !searchForm.contains(e.target)) {
          popover.classList.add('hidden');
        }
      });

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
          popover.classList.add('hidden');
        }
      });
    }

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', initLiveSearch);
    } else {
      initLiveSearch();
    }
  })();
  </script>
