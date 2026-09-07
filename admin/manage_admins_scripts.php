<script>
  (function () {
    const createOverlay = document.getElementById('create-admin-modal');
    const editOverlay = document.getElementById('edit-admin-modal');
    const deleteOverlay = document.getElementById('delete-admin-modal');
    const adminListSection = document.getElementById('admin-list-section');
    const deleteForm = document.getElementById('delete-admin-form');
    const deleteIdInput = document.getElementById('delete_admin_id_input');
    const openBtn = document.getElementById('open-create-admin-modal');
    const closeBtn = document.getElementById('close-create-admin-modal');
    const cancelBtn = document.getElementById('cancel-create-admin-modal');
    const closeEditBtn = document.getElementById('close-edit-admin-modal');
    const cancelEditBtn = document.getElementById('cancel-edit-admin-modal');
    const closeDeleteBtn = document.getElementById('close-delete-admin-modal');
    const cancelDeleteBtn = document.getElementById('cancel-delete-admin-modal');
    const confirmDeleteBtn = document.getElementById('confirm-delete-admin-btn');
    const shouldOpen = document.body.dataset.openModal === '1';

    function animateOpen(overlay) {
      if (!overlay) return;
      overlay.classList.add('open');
      overlay.setAttribute('aria-hidden', 'false');
      requestAnimationFrame(function () {
        overlay.classList.add('is-visible');
      });
    }
    function animateClose(overlay) {
      if (!overlay) return;
      overlay.classList.remove('is-visible');
      window.setTimeout(function () {
        overlay.classList.remove('open');
        overlay.setAttribute('aria-hidden', 'true');
      }, 300);
    }
    function resetCreateAdminModalState() {
      // ล้างค่าในฟอร์มทั้งหมด (text, email, password)
      const createForm = document.querySelector('#create-admin-view form') || document.querySelector('#create-admin-modal form');
      if (createForm) {
        createForm.reset();
      }

      // ล้างรูปพรีวิวและคืนค่าไอคอนกล้อง
      const previewImg = document.getElementById('create-admin-preview');
      const iconWrapper = document.getElementById('create-admin-icon-wrapper');
      const avatarInput = document.getElementById('create_admin_avatar');

      if (previewImg) {
        if (avatarInput && avatarInput.dataset.previewUrl) {
          URL.revokeObjectURL(avatarInput.dataset.previewUrl);
          delete avatarInput.dataset.previewUrl;
        }
        previewImg.src = '';
        previewImg.classList.add('hidden');
      }
      if (iconWrapper) {
        iconWrapper.classList.remove('hidden');
      }
      if (avatarInput) {
        avatarInput.value = '';
      }
    }
    function openCreateModal() { closeDeleteModal(); animateOpen(createOverlay); }
    function closeCreateModal() { animateClose(createOverlay); resetCreateAdminModalState(); }
    function showEditModal() { closeCreateModal(); closeDeleteModal(); animateOpen(editOverlay); }
    function resetEditAdminAvatarState() {
      const previewImg = document.getElementById('edit-admin-preview');
      const iconWrapper = document.getElementById('edit-admin-icon-wrapper');
      const avatarInput = document.getElementById('edit_admin_avatar');
      if (previewImg) {
        if (avatarInput && avatarInput.dataset.previewUrl) {
          URL.revokeObjectURL(avatarInput.dataset.previewUrl);
          delete avatarInput.dataset.previewUrl;
        }
        previewImg.src = '';
        previewImg.classList.add('hidden');
      }
      if (iconWrapper) {
        iconWrapper.classList.remove('hidden');
      }
      if (avatarInput) {
        avatarInput.value = '';
      }
    }
    function closeEditModal() { animateClose(editOverlay); resetEditAdminAvatarState(); }
    function openDeleteModal() { animateOpen(deleteOverlay); }
    function resetDeleteSubmitButton() {
      if (!(confirmDeleteBtn instanceof HTMLButtonElement)) return;
      confirmDeleteBtn.disabled = false;
      confirmDeleteBtn.classList.remove('opacity-75', 'cursor-not-allowed');
      confirmDeleteBtn.innerHTML = confirmDeleteBtn.dataset.originalHtml || confirmDeleteBtn.innerHTML;
    }
    function closeDeleteModal() {
      animateClose(deleteOverlay);
      resetDeleteSubmitButton();
    }

    function showDeleteConfirmModal(id, name, email) {
      closeCreateModal();
      closeEditModal();
      if (deleteIdInput) deleteIdInput.value = String(id);
      const elName = document.getElementById('delete_admin_display_name');
      const elEmail = document.getElementById('delete_admin_display_email');
      if (elName) elName.textContent = name || '';
      if (elEmail) elEmail.textContent = email || '';
      openDeleteModal();
    }

    if (openBtn) openBtn.addEventListener('click', openCreateModal);
    if (closeBtn) closeBtn.addEventListener('click', closeCreateModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeCreateModal);
    if (closeEditBtn) closeEditBtn.addEventListener('click', closeEditModal);
    if (cancelEditBtn) cancelEditBtn.addEventListener('click', closeEditModal);
    if (closeDeleteBtn) closeDeleteBtn.addEventListener('click', closeDeleteModal);
    if (cancelDeleteBtn) cancelDeleteBtn.addEventListener('click', closeDeleteModal);
    if (confirmDeleteBtn && deleteForm) {
      if (!confirmDeleteBtn.dataset.originalHtml) {
        confirmDeleteBtn.dataset.originalHtml = confirmDeleteBtn.innerHTML;
      }
      confirmDeleteBtn.addEventListener('click', function () {
        const button = confirmDeleteBtn;
        button.disabled = true;
        button.classList.add('opacity-75', 'cursor-not-allowed');
        button.innerHTML = `
          <svg class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline-block" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          Deleting...
        `;
        deleteForm.submit();
      });
    }

    if (createOverlay) createOverlay.addEventListener('click', function (e) { if (e.target === createOverlay) closeCreateModal(); });
    if (editOverlay) editOverlay.addEventListener('click', function (e) { if (e.target === editOverlay) closeEditModal(); });
    if (deleteOverlay) deleteOverlay.addEventListener('click', function (e) { if (e.target === deleteOverlay) closeDeleteModal(); });

    if (adminListSection) {
      adminListSection.addEventListener('click', function (e) {
        const btn = e.target.closest('.open-delete-admin-btn');
        if (!btn) return;
        e.preventDefault();
        const id = parseInt(btn.getAttribute('data-delete-id') || '0', 10);
        if (!id) return;
        showDeleteConfirmModal(id, btn.getAttribute('data-admin-name') || '', btn.getAttribute('data-admin-email') || '');
      });
    }

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        if (deleteOverlay.classList.contains('open')) closeDeleteModal();
        else if (editOverlay.classList.contains('open')) closeEditModal();
        else if (createOverlay.classList.contains('open')) closeCreateModal();
      }
    });

    if (shouldOpen) openCreateModal();

    window.openEditModal = function (id, name, email, avatarUrl) {
      document.getElementById('edit_admin_id').value = String(id);
      document.getElementById('edit_full_name').value = name || '';
      document.getElementById('edit_email').value = email || '';
      document.getElementById('edit_password').value = '';
      resetEditAdminAvatarState();

      const previewImg = document.getElementById('edit-admin-preview');
      const iconWrapper = document.getElementById('edit-admin-icon-wrapper');

      if (avatarUrl && avatarUrl.trim() !== '') {
        if (previewImg) {
          previewImg.src = avatarUrl;
          previewImg.classList.remove('hidden');
        }
        if (iconWrapper) {
          iconWrapper.classList.add('hidden');
        }
      }

      showEditModal();
    };
  })();

  document.addEventListener('DOMContentLoaded', function () {
    const eyeClosedSvg = '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M10 3C5 3 1.73 7.11.46 9.24a1.5 1.5 0 000 1.52C1.73 12.89 5 17 10 17s8.27-4.11 9.54-6.24a1.5 1.5 0 000-1.52C18.27 7.11 15 3 10 3zm0 11a4 4 0 110-8 4 4 0 010 8z"/><path d="M10 7a3 3 0 100 6 3 3 0 000-6z"/></svg>';
    const eyeOpenSvg = '<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M3.707 2.293a1 1 0 00-1.414 1.414l14 14a1 1 0 001.414-1.414l-1.473-1.473A10.014 10.014 0 0019.542 10C18.268 5.943 14.478 3 10 3a9.958 9.958 0 00-4.512 1.074l-1.78-1.781zm4.261 4.26l1.514 1.515a2.003 2.003 0 012.45 2.45l1.514 1.514a4 4 0 00-5.478-5.478z" clip-rule="evenodd" /><path d="M12.454 16.697L9.75 13.992a4 4 0 01-3.742-3.741L2.335 6.578A9.98 9.98 0 00.458 10c1.274 4.057 5.065 7 9.542 7 .847 0 1.669-.105 2.454-.303z" /></svg>';

    document.querySelectorAll('.toggle-password-btn').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const targetId = btn.getAttribute('data-target');
        const input = targetId ? document.getElementById(targetId) : null;
        if (!(input instanceof HTMLInputElement)) return;

        const showing = input.type === 'password';
        input.type = showing ? 'text' : 'password';
        btn.innerHTML = showing ? eyeOpenSvg : eyeClosedSvg;
        btn.setAttribute('aria-label', showing ? 'Hide password' : 'Show password');
      });
    });

    let adminCropper = null;
    let currentAdminAvatarInput = null;
    const adminCropModal = document.getElementById('admin-crop-modal');
    const adminCropImage = document.getElementById('admin-cropper-image');

    function closeAdminCropper() {
      if (!adminCropModal) return;
      adminCropModal.classList.add('opacity-0');
      const inner = adminCropModal.querySelector('.modal-inner');
      if (inner) inner.classList.add('scale-95');

      setTimeout(function () {
        adminCropModal.classList.remove('flex');
        adminCropModal.classList.add('hidden');
        adminCropModal.setAttribute('aria-hidden', 'true');
        if (adminCropper) {
          adminCropper.destroy();
          adminCropper = null;
        }
      }, 300);
    }

    function openAdminCropper(file) {
      if (!adminCropImage || !adminCropModal) return;
      const reader = new FileReader();
      reader.onload = function (event) {
        adminCropImage.src = event.target.result;
        adminCropModal.classList.remove('hidden');
        adminCropModal.classList.add('flex');
        adminCropModal.setAttribute('aria-hidden', 'false');

        requestAnimationFrame(function () {
          adminCropModal.classList.remove('opacity-0');
          const inner = adminCropModal.querySelector('.modal-inner');
          if (inner) inner.classList.remove('scale-95');
        });

        if (adminCropper) adminCropper.destroy();
        adminCropper = new Cropper(adminCropImage, {
          aspectRatio: 1,
          viewMode: 1,
          autoCropArea: 1,
        });
      };
      reader.readAsDataURL(file);
    }

    document.addEventListener('change', function (e) {
      if (e.target && e.target.classList.contains('admin-avatar-crop-input')) {
        const file = e.target.files[0];
        if (!file) return;
        currentAdminAvatarInput = e.target;
        openAdminCropper(file);
      }
    });

    function cancelAdminCropSelection() {
      if (currentAdminAvatarInput) currentAdminAvatarInput.value = '';
      closeAdminCropper();
    }

    document.getElementById('close-admin-crop-btn')?.addEventListener('click', cancelAdminCropSelection);
    document.getElementById('cancel-admin-crop-btn')?.addEventListener('click', cancelAdminCropSelection);

    adminCropModal?.addEventListener('click', function (e) {
      if (e.target === adminCropModal) {
        cancelAdminCropSelection();
      }
    });

    document.getElementById('save-admin-crop-btn')?.addEventListener('click', function () {
      if (!adminCropper || !currentAdminAvatarInput) return;
      const btn = this;
      btn.innerHTML = 'กำลังประมวลผล...';
      btn.disabled = true;

      adminCropper.getCroppedCanvas({ width: 400, height: 400 }).toBlob(function (blob) {
        if (!blob) {
          btn.innerHTML = 'ยืนยันการคอป';
          btn.disabled = false;
          return;
        }

        const originalName = currentAdminAvatarInput.files[0]?.name || 'admin_avatar_cropped.jpg';
        const croppedFile = new File([blob], originalName, { type: 'image/jpeg', lastModified: Date.now() });

        const dataTransfer = new DataTransfer();
        dataTransfer.items.add(croppedFile);
        currentAdminAvatarInput.files = dataTransfer.files;

        const label = currentAdminAvatarInput.closest('label');
        const previewImg = label
          ? label.querySelector('.admin-avatar-crop-preview')
          : document.querySelector('.admin-avatar-crop-preview');
        const iconWrapper = label
          ? label.querySelector('[id$="-icon-wrapper"]')
          : null;

        if (previewImg) {
          if (currentAdminAvatarInput.dataset.previewUrl) {
            URL.revokeObjectURL(currentAdminAvatarInput.dataset.previewUrl);
          }
          const newPreviewUrl = URL.createObjectURL(blob);
          currentAdminAvatarInput.dataset.previewUrl = newPreviewUrl;
          previewImg.src = newPreviewUrl;
          previewImg.classList.remove('hidden');
        }
        if (iconWrapper) {
          iconWrapper.classList.add('hidden');
        }

        btn.innerHTML = 'ยืนยันการคอป';
        btn.disabled = false;
        closeAdminCropper();
      }, 'image/jpeg', 0.9);
    });
  });
</script>
