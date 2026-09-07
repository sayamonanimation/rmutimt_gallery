<?php
declare(strict_types=1);

if (!function_exists('csrf_input')) {
    require_once __DIR__ . '/../config/csrf.php';
}

$currentPage = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
$isMyProjectsPage = $currentPage === 'my_projects.php';
$isUploadProjectPage = $currentPage === 'upload.php';
$activeMenuClass = 'bg-[var(--mt-red)] text-white shadow-md shadow-red-900/40';
$inactiveMenuClass = 'text-white/80 hover:bg-white/10 hover:text-white hover:translate-x-1 transition-all duration-200';

$sidebarAdminName = trim((string) ($adminName ?? $_SESSION['name'] ?? $_SESSION['user_name'] ?? 'Student'));
$sidebarAdminEmail = trim((string) ($adminEmail ?? $_SESSION['email'] ?? ''));

$sidebarAvatarUrl = '';
if (isset($_SESSION['user_id']) && isset($pdo)) {
    try {
        $stmtAvatar = $pdo->prepare('SELECT avatar_filename FROM users WHERE id = :id LIMIT 1');
        $stmtAvatar->execute([':id' => (int) $_SESSION['user_id']]);
        $row = $stmtAvatar->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['avatar_filename'])) {
            $sidebarAvatarUrl = '../uploads/admins/' . rawurlencode($row['avatar_filename']);
        }
    } catch (Throwable $e) {
    }
}

$sidebarProfileImageUrl = trim((string) ($_SESSION['profile_image'] ?? ''));
if ($sidebarProfileImageUrl === '' && $sidebarAvatarUrl !== '') {
    $sidebarProfileImageUrl = $sidebarAvatarUrl;
}
if ($sidebarProfileImageUrl !== '' && trim((string) ($_SESSION['profile_image'] ?? '')) === '') {
    $_SESSION['profile_image'] = $sidebarProfileImageUrl;
}

$firstLetter = $sidebarAdminName !== '' ? (function_exists('mb_substr') ? mb_substr($sidebarAdminName, 0, 1, 'UTF-8') : substr($sidebarAdminName, 0, 1)) : 'S';
$sidebarAvatarText = strtoupper((string) $firstLetter);

$profileModalPreviewSrc = $sidebarProfileImageUrl !== ''
    ? $sidebarProfileImageUrl
    : 'data:image/svg+xml,' . rawurlencode(
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96"><circle cx="48" cy="48" r="48" fill="#374151"/>'
        . '<text x="48" y="62" text-anchor="middle" font-size="40" fill="#ffffff" font-family="system-ui,sans-serif">'
        . htmlspecialchars($sidebarAvatarText, ENT_XML1 | ENT_QUOTES, 'UTF-8')
        . '</text></svg>'
    );
?>
<link href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
<!-- Sidebar Overlay (mobile only) -->
<div id="sidebar-overlay" class="fixed inset-0 z-40 bg-black/50 hidden lg:hidden" onclick="closeSidebar()"></div>

<aside id="admin-sidebar" class="fixed inset-y-0 left-0 z-50 w-64 flex flex-col flex-shrink-0 overflow-hidden bg-[#0F1114] text-white border-r border-black/40 transform -translate-x-full transition-transform duration-300 ease-in-out lg:translate-x-0 lg:static lg:z-auto">
  <div class="h-16 px-4 flex items-center justify-between border-b border-white/10">
    <a href="../index.php" class="group flex items-center gap-3 px-2.5 py-1.5 rounded-xl hover:bg-white/10 transition-all duration-200" style="text-decoration:none;" title="กลับไปยังหน้าหลัก / Return to Homepage">
      <img src="../assets/images/mt-logo.png" alt="MT Logo" class="h-8 w-8 object-contain group-hover:scale-105 transition-transform duration-200" />
      <div class="text-sm font-semibold tracking-wide text-white group-hover:text-rose-400 transition-colors">RMUTI MT Gallery</div>
    </a>
    <!-- Close button (mobile only) -->
    <button type="button" onclick="closeSidebar()" class="lg:hidden p-1 rounded-md text-white/70 hover:text-white hover:bg-white/10" aria-label="Close menu">
      <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
    </button>
  </div>

  <div class="px-4 pt-6">
    <div class="text-[10px] tracking-[0.16em] uppercase text-white/45 mb-3" data-th="เมนูหลัก" data-en="Main Menu">เมนูหลัก</div>
    <nav class="space-y-1">
      <a href="./my_projects.php" class="flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold <?= $isMyProjectsPage ? $activeMenuClass : $inactiveMenuClass ?>">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M2.75 6.75A2.75 2.75 0 015.5 4h2.2c.65 0 1.26.3 1.68.8l.6.72c.42.5 1.03.78 1.67.78h2.85a2.75 2.75 0 012.75 2.75v5A2.75 2.75 0 0114.5 17h-9A2.75 2.75 0 012.75 14.25v-7.5z" />
        </svg>
        <span data-th="โปรเจกต์ของฉัน" data-en="My Projects">โปรเจกต์ของฉัน</span>
      </a>

      <a href="./upload.php" class="flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold <?= $isUploadProjectPage ? $activeMenuClass : $inactiveMenuClass ?>">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
          <path stroke-linecap="round" stroke-linejoin="round" d="M6 14.5h6M10 13V7.75m0 0l-2.25 2.25M10 7.75L12.25 10M4.5 13.75A3.75 3.75 0 014.5 6.25a4.5 4.5 0 018.7-1.6A3.2 3.2 0 0116.5 7.8a3.2 3.2 0 01-1.2 6.2H14" />
        </svg>
        <span data-th="อัปโหลดโปรเจกต์" data-en="Upload Project">อัปโหลดโปรเจกต์</span>
      </a>
    </nav>
  </div>

  <div class="mt-auto flex flex-col w-full pb-4">
    <div class="border-t border-white/10 px-5 py-3 mt-2">
      <a href="../logout.php" class="flex items-center gap-2 text-sm text-white/80 hover:text-white">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3 4a1 1 0 011-1h7a1 1 0 010 2H5v10h6a1 1 0 110 2H4a1 1 0 01-1-1V4zm9.293 1.293a1 1 0 011.414 0l4 4a.997.997 0 01.083 1.32l-.083.094-4 4a1 1 0 01-1.497-1.32l.083-.094L14.586 11H8a1 1 0 110-2h6.586l-2.293-2.293a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
        <span data-th="ออกจากระบบ" data-en="Sign Out">ออกจากระบบ</span>
      </a>
    </div>
    <div class="mx-6 border-t border-white/10 my-1"></div>
    <div class="px-5 pt-2 flex items-center gap-3">
      <button type="button" onclick="openProfileModal(event)" title="เปลี่ยนรูปโปรไฟล์" class="relative flex h-10 w-10 shrink-0 cursor-pointer items-center justify-center overflow-hidden rounded-full border border-gray-600 bg-gray-700 transition-transform hover:scale-105 focus:outline-none focus:ring-2 focus:ring-white/30">
        <?php
        $sidebarTriggerImg = trim((string) ($_SESSION['profile_image'] ?? ''));
        if ($sidebarTriggerImg === '' && $sidebarProfileImageUrl !== '') {
            $sidebarTriggerImg = $sidebarProfileImageUrl;
        }
        ?>
        <?php if ($sidebarTriggerImg !== ''): ?>
          <img src="<?= htmlspecialchars($sidebarTriggerImg, ENT_QUOTES, 'UTF-8') ?>" alt="Profile" class="h-full w-full object-cover">
        <?php else: ?>
          <span class="text-sm font-semibold text-white/90"><?= htmlspecialchars($sidebarAvatarText, ENT_QUOTES, 'UTF-8') ?></span>
        <?php endif; ?>
      </button>
      <div class="min-w-0 flex-1">
        <p class="truncate text-sm font-semibold text-white"><?= htmlspecialchars($sidebarAdminName, ENT_QUOTES, 'UTF-8') ?></p>
        <p class="truncate text-xs text-gray-400"><?= htmlspecialchars($sidebarAdminEmail, ENT_QUOTES, 'UTF-8') ?></p>
      </div>
    </div>
  </div>
</aside>

<div id="profile-modal" class="fixed inset-0 z-[9999] hidden items-center justify-center bg-black/50 opacity-0 transition-opacity duration-300" aria-hidden="true" data-preview-src="<?= htmlspecialchars($profileModalPreviewSrc, ENT_QUOTES, 'UTF-8') ?>">
    <div class="modal-inner w-full max-w-md scale-95 rounded-2xl bg-white p-6 shadow-2xl transition-transform duration-300 dark:bg-[#1e1e1e] dark:border dark:border-[#333333]">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-xl font-bold text-gray-900 dark:text-white">เปลี่ยนรูปโปรไฟล์</h3>
            <button type="button" onclick="closeProfileModal()" class="text-2xl leading-none text-gray-400 hover:text-gray-600 dark:text-gray-400 dark:hover:text-gray-200" aria-label="ปิด">&times;</button>
        </div>
        <form id="profile-form" action="./update_profile.php" method="POST" enctype="multipart/form-data">
            <?= csrf_input() ?>
            <div class="mb-6 flex flex-col items-center">
                <div id="preview-container" class="relative mb-4 h-24 w-24 overflow-hidden rounded-full border-4 border-gray-100 bg-gray-50 shadow-sm dark:border-[#333333] dark:bg-[#262626]">
                    <img id="profile-preview" src="<?= htmlspecialchars($profileModalPreviewSrc, ENT_QUOTES, 'UTF-8') ?>" alt="Preview" class="h-full w-full object-cover">
                </div>
                <div id="cropper-container" class="hidden mb-4 w-full h-[280px] overflow-hidden bg-[#F3F4F6] border border-[#E5E7EB] rounded-xl dark:bg-[#262626] dark:border-[#333333]">
                    <img id="cropper-image" src="" alt="Crop" class="max-w-full block">
                </div>
                <input type="file" id="profile_image_input" name="profile_image" accept="image/jpeg,image/png,image/webp" class="hidden" onchange="previewProfileImage(event)">
                <label for="profile_image_input" class="cursor-pointer rounded-full bg-[#D32F2F] px-4 py-2 text-sm font-medium text-white hover:bg-[#B71C1C]">
                    เลือกรูปภาพใหม่
                </label>
                <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">รองรับ JPG, PNG, WEBP (ขนาดไม่เกิน 2MB)</p>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" onclick="closeProfileModal()" class="rounded-lg border border-gray-200 px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50 dark:border-[#333333] dark:text-gray-300 dark:hover:bg-gray-800">ยกเลิก</button>
                <button type="submit" class="rounded-lg bg-[#D32F2F] px-4 py-2 text-sm font-medium text-white hover:bg-[#B71C1C]">บันทึกรูปภาพ</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const profileModal = document.getElementById('profile-modal');
    if (!profileModal) return;
    if (profileModal.parentNode !== document.body) {
        document.body.appendChild(profileModal);
    }
    profileModal.addEventListener('click', function (e) {
        if (e.target === profileModal) {
            window.closeProfileModal();
        }
    });
});

let profileCropper = null;

window.openProfileModal = function (event) {
    if (event) event.preventDefault();
    const modal = document.getElementById('profile-modal');
    if (!modal) return;

    const baseline = modal.getAttribute('data-preview-src') || '';
    const preview = document.getElementById('profile-preview');
    const fileInput = document.getElementById('profile_image_input');
    const previewContainer = document.getElementById('preview-container');
    const cropperContainer = document.getElementById('cropper-container');

    if (preview && baseline) preview.src = baseline;
    if (fileInput) fileInput.value = '';
    if (previewContainer) previewContainer.classList.remove('hidden');
    if (cropperContainer) cropperContainer.classList.add('hidden');
    if (profileCropper) {
        profileCropper.destroy();
        profileCropper = null;
    }

    modal.classList.remove('hidden');
    modal.classList.add('flex');
    modal.setAttribute('aria-hidden', 'false');
    requestAnimationFrame(function () {
        modal.classList.remove('opacity-0');
        const inner = modal.querySelector('.modal-inner');
        if (inner) inner.classList.remove('scale-95');
    });
};

window.closeProfileModal = function () {
    const modal = document.getElementById('profile-modal');
    if (!modal) return;

    modal.classList.add('opacity-0');
    const inner = modal.querySelector('.modal-inner');
    if (inner) inner.classList.add('scale-95');

    setTimeout(function () {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        modal.setAttribute('aria-hidden', 'true');
        if (profileCropper) {
            profileCropper.destroy();
            profileCropper = null;
        }
    }, 300);
};

window.previewProfileImage = function (event) {
    const file = event.target.files && event.target.files[0];
    if (!file) return;

    const reader = new FileReader();
    reader.onload = function (e) {
        const previewContainer = document.getElementById('preview-container');
        const cropperContainer = document.getElementById('cropper-container');
        const cropperImage = document.getElementById('cropper-image');

        if (previewContainer) previewContainer.classList.add('hidden');
        if (cropperContainer) cropperContainer.classList.remove('hidden');
        cropperImage.src = e.target.result;

        if (profileCropper) {
            profileCropper.destroy();
        }
        profileCropper = new Cropper(cropperImage, {
            aspectRatio: 1,
            viewMode: 1,
            autoCropArea: 1,
        });
    };
    reader.readAsDataURL(file);
};

document.addEventListener('DOMContentLoaded', function() {
    const profileForm = document.getElementById('profile-form');
    if (profileForm) {
        profileForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const submitBtn = profileForm.querySelector('button[type="submit"]');

            if (profileCropper) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = 'กำลังบันทึก...';

                profileCropper.getCroppedCanvas({ width: 400, height: 400 }).toBlob(function(blob) {
                    const formData = new FormData(profileForm);
                    formData.set('profile_image', blob, 'profile_cropped.jpg');

                    fetch(profileForm.action, {
                        method: 'POST',
                        body: formData
                    })
                    .then(function(response) {
                        if (response.ok) {
                            window.location.reload();
                        } else {
                            alert('เกิดข้อผิดพลาดในการอัปโหลด');
                            submitBtn.disabled = false;
                            submitBtn.innerHTML = 'บันทึกรูปภาพ';
                        }
                    })
                    .catch(function() {
                        alert('เกิดข้อผิดพลาดในการเชื่อมต่อ');
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = 'บันทึกรูปภาพ';
                    });
                }, 'image/jpeg', 0.9);
            } else {
                window.closeProfileModal();
            }
        });
    }
});
</script>
