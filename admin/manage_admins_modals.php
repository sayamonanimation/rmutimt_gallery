<?php
declare(strict_types=1);
?>
<div id="create-admin-modal" class="modal-overlay" aria-hidden="true">
  <div class="modal-panel w-full max-w-[560px] rounded-2xl bg-[#F2F3F4] border-t-4 border-[var(--mt-red)] shadow-[0_24px_60px_rgba(0,0,0,0.35)] overflow-hidden dark:bg-[#1e1e1e] dark:border-[#333333]">
    <div class="flex items-start justify-between border-b border-[#D7D9DB] px-6 py-5 dark:border-[#333333]">
      <div class="flex items-center gap-3">
        <div class="h-10 w-10 rounded-xl bg-[#FFEDEE] text-[var(--mt-red)] dark:bg-rose-950/40 dark:text-rose-400 grid place-items-center">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M10 10a4 4 0 100-8 4 4 0 000 8z"/><path fill-rule="evenodd" d="M.458 16.042C1.732 13.133 4.522 11 10 11a.75.75 0 010 1.5c-4.9 0-7.18 1.74-8.18 4.06a.75.75 0 01-1.37-.6z" clip-rule="evenodd"/><path d="M15.5 7a.75.75 0 01.75.75V9h1.25a.75.75 0 010 1.5h-1.25v1.25a.75.75 0 01-1.5 0V10.5H13.5a.75.75 0 010-1.5h1.25V7.75A.75.75 0 0115.5 7z"/></svg>
        </div>
        <h2 class="admin-modal-title dark:text-white">Create New Admin Account</h2>
      </div>
      <button type="button" id="close-create-admin-modal" class="text-3xl leading-none text-[#9AA0A6] hover:text-[#5F6368] dark:text-gray-400 dark:hover:text-gray-200">&times;</button>
    </div>

    <form method="post" action="./manage_admins.php" enctype="multipart/form-data" class="px-6 py-5">
      <input type="hidden" name="action" value="create_admin" />
      <?= csrf_input() ?>
      <div class="mb-6 text-center">
        <span class="mb-3 block text-[12px] font-bold uppercase tracking-[0.08em] text-[#9AA0A6] dark:text-gray-400">รูปโปรไฟล์ (AVATAR)</span>
        
        <label for="create_admin_avatar" class="group relative mx-auto grid h-28 w-28 cursor-pointer place-items-center overflow-hidden rounded-full border-2 border-dashed border-[#FCA5A5] bg-[#FFF5F5] text-[var(--mt-red)] transition-colors hover:bg-[#FFEFEF] dark:border-rose-900/60 dark:bg-rose-950/30 dark:text-rose-400">
          <div id="create-admin-icon-wrapper" class="flex flex-col items-center">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" viewBox="0 0 20 20" fill="currentColor">
              <path fill-rule="evenodd" d="M4 5a2 2 0 00-2 2v8a2 2 0 002 2h12a2 2 0 002-2V7a2 2 0 00-2-2h-1.586a1 1 0 01-.707-.293l-1.121-1.121A2 2 0 0011.172 3H8.828a2 2 0 00-1.414.586L6.293 4.707A1 1 0 015.586 5H4zm6 9a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd" />
            </svg>
          </div>

          <img id="create-admin-preview" class="admin-avatar-crop-preview absolute inset-0 hidden h-full w-full object-cover" alt="Avatar preview" />

          <input type="file" id="create_admin_avatar" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp" class="admin-avatar-crop-input hidden" />
        </label>
        <p class="mt-2 text-[11px] text-[#A6ADB6] dark:text-gray-400">คลิกเพื่อเลือกรูป</p>
      </div>
      <div class="mt-6 space-y-4">
        <div>
          <label for="create_name" class="admin-modal-label dark:text-gray-400">FULL NAME</label>
          <div class="relative">
            <span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-[#B8BDC2]"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M10 10a4 4 0 100-8 4 4 0 000 8z"/><path fill-rule="evenodd" d="M.458 16.042C1.732 13.133 4.522 11 10 11s8.268 2.133 9.542 5.042A1 1 0 0118.63 17H1.37a1 1 0 01-.912-1.458z" clip-rule="evenodd"/></svg></span>
            <input id="create_name" name="name" type="text" class="input bg-[#F2F3F4] dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" placeholder="Enter full name" required />
          </div>
        </div>
        <div>
          <label for="create_email" class="admin-modal-label dark:text-gray-400">EMAIL</label>
          <div class="relative">
            <span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-[#B8BDC2]"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M2.94 6.34A2 2 0 014.62 5h10.76a2 2 0 011.68 1.34L10 10.28 2.94 6.34z"/><path d="M18 8.12l-7.45 4.16a1 1 0 01-1.1 0L2 8.12V14a2 2 0 002 2h12a2 2 0 002-2V8.12z"/></svg></span>
            <input id="create_email" name="email" type="email" class="input bg-[#F2F3F4] dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" placeholder="admin@rmuti.ac.th" required />
          </div>
        </div>
        <div>
          <label for="create_password" class="admin-modal-label dark:text-gray-400">PASSWORD</label>
          <div class="relative">
            <span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-[#B8BDC2]"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 8V6a5 5 0 1110 0v2h1a1 1 0 011 1v8a1 1 0 01-1 1H4a1 1 0 01-1-1V9a1 1 0 011-1h1zm2 0h6V6a3 3 0 10-6 0v2z" clip-rule="evenodd"/></svg></span>
            <input id="create_password" name="password" type="password" class="input pr-12 bg-[#F2F3F4] dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" placeholder="••••••••" required />
            <button type="button" class="toggle-password-btn absolute inset-y-0 right-4 flex items-center text-[#B8BDC2] hover:text-[#5F6368] dark:hover:text-gray-300 transition-colors" data-target="create_password" aria-label="Show password">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M10 3C5 3 1.73 7.11.46 9.24a1.5 1.5 0 000 1.52C1.73 12.89 5 17 10 17s8.27-4.11 9.54-6.24a1.5 1.5 0 000-1.52C18.27 7.11 15 3 10 3zm0 11a4 4 0 110-8 4 4 0 010 8z"/><path d="M10 7a3 3 0 100 6 3 3 0 000-6z"/></svg>
            </button>
          </div>
        </div>
      </div>
      <div class="mt-6 grid grid-cols-2 gap-3">
        <button type="button" id="cancel-create-admin-modal" class="admin-modal-btn h-12 rounded-xl border border-[#D5D8DB] bg-white text-[#555] hover:bg-[#F8F9FA] dark:border-[#333333] dark:bg-[#1e1e1e] dark:text-gray-300 dark:hover:bg-gray-800">Cancel</button>
        <button type="submit" class="admin-modal-btn h-12 rounded-xl border-0 bg-[var(--mt-red)] text-white hover:bg-[var(--mt-red-dark)] inline-flex items-center justify-center gap-2">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M10 10a4 4 0 100-8 4 4 0 000 8z"/><path fill-rule="evenodd" d="M.458 16.042C1.732 13.133 4.522 11 10 11a.75.75 0 010 1.5c-4.9 0-7.18 1.74-8.18 4.06a.75.75 0 01-1.37-.6z" clip-rule="evenodd"/><path d="M15.5 7a.75.75 0 01.75.75V9h1.25a.75.75 0 010 1.5h-1.25v1.25a.75.75 0 01-1.5 0V10.5H13.5a.75.75 0 010-1.5h1.25V7.75A.75.75 0 0115.5 7z"/></svg>
          Create Admin
        </button>
      </div>
    </form>
  </div>
</div>

<div id="edit-admin-modal" class="modal-overlay" aria-hidden="true">
  <div class="modal-panel w-full max-w-[560px] rounded-2xl bg-white border-t-4 border-[var(--mt-red)] shadow-[0_24px_60px_rgba(0,0,0,0.35)] overflow-hidden dark:bg-[#1e1e1e] dark:border-[#333333]">
    <div class="flex items-start justify-between border-b border-[#E5E7EB] px-6 py-5 dark:border-[#333333]">
      <div class="flex items-center gap-3"><div class="h-10 w-10 rounded-xl bg-[#FFEDEE] text-[var(--mt-red)] dark:bg-rose-950/40 dark:text-rose-400 grid place-items-center"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M17.414 2.586a2 2 0 010 2.828l-8.5 8.5a1 1 0 01-.39.244l-4 1.333a1 1 0 01-1.264-1.264l1.333-4a1 1 0 01.244-.39l8.5-8.5a2 2 0 012.828 0z"/></svg></div><h2 class="admin-modal-title dark:text-white">Edit Admin Account</h2></div>
      <button type="button" id="close-edit-admin-modal" class="text-3xl leading-none text-[#9AA0A6] hover:text-[#5F6368] dark:text-gray-400 dark:hover:text-gray-200">&times;</button>
    </div>
    <form method="post" action="./manage_admins.php" enctype="multipart/form-data" class="px-6 py-5">
      <input type="hidden" name="edit_admin" value="1" />
      <input type="hidden" name="admin_id" id="edit_admin_id" />
      <?= csrf_input() ?>
      <div class="mt-2 space-y-4">
        <div class="mb-6 text-center">
          <span class="mb-3 block text-[12px] font-bold uppercase tracking-[0.08em] text-[#9AA0A6] dark:text-gray-400">รูปโปรไฟล์ (AVATAR)</span>
          
          <label for="edit_admin_avatar" class="group relative mx-auto grid h-28 w-28 cursor-pointer place-items-center overflow-hidden rounded-full border-2 border-dashed border-[#FCA5A5] bg-[#FFF5F5] text-[var(--mt-red)] transition-colors hover:bg-[#FFEFEF] dark:border-rose-900/60 dark:bg-rose-950/30 dark:text-rose-400">
            <div id="edit-admin-icon-wrapper" class="flex flex-col items-center">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd" d="M4 5a2 2 0 00-2 2v8a2 2 0 002 2h12a2 2 0 002-2V7a2 2 0 00-2-2h-1.586a1 1 0 01-.707-.293l-1.121-1.121A2 2 0 0011.172 3H8.828a2 2 0 00-1.414.586L6.293 4.707A1 1 0 015.586 5H4zm6 9a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd" />
              </svg>
            </div>

            <img id="edit-admin-preview" class="admin-avatar-crop-preview absolute inset-0 hidden h-full w-full object-cover" alt="Avatar preview" />

            <input type="file" id="edit_admin_avatar" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp" class="admin-avatar-crop-input hidden" />
          </label>
          <p class="mt-2 text-[11px] text-[#A6ADB6] dark:text-gray-400">คลิกเพื่อเลือกรูป</p>
        </div>
        <div><label for="edit_full_name" class="admin-modal-label dark:text-gray-400">FULL NAME</label><div class="relative"><span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-[#B8BDC2]"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M10 10a4 4 0 100-8 4 4 0 000 8z"/><path fill-rule="evenodd" d="M.458 16.042C1.732 13.133 4.522 11 10 11s8.268 2.133 9.542 5.042A1 1 0 0118.63 17H1.37a1 1 0 01-.912-1.458z" clip-rule="evenodd"/></svg></span><input id="edit_full_name" name="edit_full_name" type="text" class="input dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" required /></div></div>
        <div><label for="edit_email" class="admin-modal-label dark:text-gray-400">EMAIL</label><div class="relative"><span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-[#B8BDC2]"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M2.94 6.34A2 2 0 014.62 5h10.76a2 2 0 011.68 1.34L10 10.28 2.94 6.34z"/><path d="M18 8.12l-7.45 4.16a1 1 0 01-1.1 0L2 8.12V14a2 2 0 002 2h12a2 2 0 002-2V8.12z"/></svg></span><input id="edit_email" name="edit_email" type="email" class="input dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" required /></div></div>
        <div><label for="edit_password" class="admin-modal-label dark:text-gray-400">PASSWORD</label><div class="relative"><span class="pointer-events-none absolute inset-y-0 left-4 flex items-center text-[#B8BDC2]"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 8V6a5 5 0 1110 0v2h1a1 1 0 011 1v8a1 1 0 01-1 1H4a1 1 0 01-1-1V9a1 1 0 011-1h1zm2 0h6V6a3 3 0 10-6 0v2z" clip-rule="evenodd"/></svg></span><input id="edit_password" name="edit_password" type="password" class="input pr-12 dark:bg-[#1e1e1e] dark:border-gray-700 dark:text-white dark:placeholder-gray-500 dark:focus:border-[var(--mt-red)] dark:focus:ring-[var(--mt-red)]/20" placeholder="••••••••" /><button type="button" class="toggle-password-btn absolute inset-y-0 right-4 flex items-center text-[#B8BDC2] hover:text-[#5F6368] dark:hover:text-gray-300 transition-colors" data-target="edit_password" aria-label="Show password"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M10 3C5 3 1.73 7.11.46 9.24a1.5 1.5 0 000 1.52C1.73 12.89 5 17 10 17s8.27-4.11 9.54-6.24a1.5 1.5 0 000-1.52C18.27 7.11 15 3 10 3zm0 11a4 4 0 110-8 4 4 0 010 8z"/><path d="M10 7a3 3 0 100 6 3 3 0 000-6z"/></svg></button></div></div>
      </div>
      <div class="mt-6 grid grid-cols-2 gap-3">
        <button type="button" id="cancel-edit-admin-modal" class="admin-modal-btn h-12 rounded-xl border border-[#E0E0E0] bg-white text-[#555] hover:bg-[#F8F9FA] dark:border-[#333333] dark:bg-[#1e1e1e] dark:text-gray-300 dark:hover:bg-gray-800">Cancel</button>
        <button type="submit" class="admin-modal-btn h-12 rounded-xl border-0 bg-[var(--mt-red)] text-white hover:bg-[var(--mt-red-dark)] inline-flex items-center justify-center gap-2">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M16.704 5.29a1 1 0 010 1.42l-7.3 7.3a1 1 0 01-1.415 0l-3.3-3.3a1 1 0 111.414-1.414l2.593 2.592 6.593-6.592a1 1 0 011.415 0z" clip-rule="evenodd"/></svg>
          Save Changes
        </button>
      </div>
    </form>
  </div>
</div>

<div id="delete-admin-modal" class="modal-overlay" aria-hidden="true">
  <div class="modal-panel w-full max-w-[420px] rounded-2xl bg-white border-t-4 border-[var(--mt-red)] shadow-[0_24px_60px_rgba(0,0,0,0.35)] overflow-hidden dark:bg-[#1e1e1e] dark:border-[#333333]">
    <div class="flex items-start justify-between border-b border-[#E5E7EB] px-6 py-5 dark:border-[#333333]">
      <div class="flex min-w-0 flex-1 items-start gap-3">
        <div class="h-10 w-10 shrink-0 rounded-xl bg-[#FFEDEE] text-[var(--mt-red)] dark:bg-rose-950/40 dark:text-rose-400 grid place-items-center"><svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.5 2a1 1 0 00-.894.553L7.382 3H5a1 1 0 100 2h.293l.854 10.243A2 2 0 008.14 17h3.72a2 2 0 001.993-1.757L14.707 5H15a1 1 0 100-2h-2.382l-.224-.447A1 1 0 0011.5 2h-3zM9 8a1 1 0 012 0v5a1 1 0 11-2 0V8z" clip-rule="evenodd"/></svg></div>
        <div class="min-w-0"><h2 class="admin-modal-title text-[22px] sm:text-[24px] dark:text-white">ลบผู้ดูแลระบบ</h2><p class="mt-1.5 text-sm leading-snug text-[var(--text-muted)] dark:text-gray-400">การลบบัญชีนี้ไม่สามารถย้อนกลับได้ กรุณายืนยันอีกครั้ง</p></div>
      </div>
      <button type="button" id="close-delete-admin-modal" class="shrink-0 text-3xl leading-none text-[#9AA0A6] hover:text-[#5F6368] dark:text-gray-400 dark:hover:text-gray-200">&times;</button>
    </div>
    <div class="px-6 py-5">
      <div class="admin-modal-label mb-2 dark:text-gray-400">บัญชีที่เลือก</div>
      <div class="rounded-xl border border-[var(--border)] bg-[#F8F9FA] px-4 py-3 dark:bg-[#262626] dark:border-[#333333]">
        <div id="delete_admin_display_name" class="truncate text-sm font-semibold text-[#1E1E1E] dark:text-gray-100"></div>
        <div id="delete_admin_display_email" class="mt-1 truncate text-sm text-[var(--text-muted)]"></div>
      </div>
      <div class="mt-6 grid grid-cols-2 gap-3">
        <button type="button" id="cancel-delete-admin-modal" class="admin-modal-btn h-12 rounded-xl border border-[#E0E0E0] bg-white text-[#555] hover:bg-[#F8F9FA] dark:border-[#333333] dark:bg-[#1e1e1e] dark:text-gray-300 dark:hover:bg-gray-800">ยกเลิก</button>
        <button type="button" id="confirm-delete-admin-btn" class="admin-modal-btn inline-flex h-12 items-center justify-center gap-2 rounded-xl border-0 bg-[var(--mt-red)] text-white transition-all duration-200 hover:-translate-y-[1px] hover:bg-[var(--mt-red-dark)]">
          <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6.75h18M9.75 6.75V5.25A2.25 2.25 0 0112 3h0a2.25 2.25 0 012.25 2.25v1.5m-7.5 0L7.5 19.5A2.25 2.25 0 009.75 21h4.5a2.25 2.25 0 002.25-1.5l.75-12.75M10 11.25v5.5m4-5.5v5.5"/></svg>
          ลบถาวร
        </button>
      </div>
    </div>
  </div>
</div>

<form id="delete-admin-form" method="post" action="./manage_admins.php" hidden>
  <?= csrf_input() ?>
  <input type="hidden" name="delete_id" id="delete_admin_id_input" value="" />
</form>
