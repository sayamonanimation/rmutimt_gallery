<?php
declare(strict_types=1);
?>
  <!-- Auth Modals Overlay Component -->
  <div id="auth-modal-overlay" class="modal-overlay" aria-hidden="true">
    <div class="auth-modal" role="dialog" aria-modal="true" aria-labelledby="auth-title">
      <!-- Sign In View -->
      <div id="auth-signin-view">
        <div class="auth-header">
          <div>
            <h2 id="auth-title" class="auth-title" data-th="เข้าสู่ระบบ" data-en="Sign In">เข้าสู่ระบบ</h2>
            <p class="auth-subtitle" data-th="เข้าใช้งานบัญชีคลังวิทยานิพนธ์ของคุณ" data-en="Access your thesis gallery account">เข้าใช้งานบัญชีคลังวิทยานิพนธ์ของคุณ</p>
          </div>
          <button type="button" class="auth-close" id="auth-close-btn" aria-label="Close">&times;</button>
        </div>
        <div class="auth-body">
          <div id="signin-popup-error" class="signin-popup-error" role="alert" aria-live="assertive"></div>
          <form method="POST" action="./auth_process.php">
            <input type="hidden" name="action" value="signin" />
            <?= csrf_input() ?>
            <div class="auth-field">
              <label for="signin-email" data-th="อีเมล" data-en="EMAIL">อีเมล</label>
              <input id="signin-email" name="email" type="email" required placeholder="example@rmuti.ac.th" data-th-placeholder="example@rmuti.ac.th" data-en-placeholder="example@rmuti.ac.th" />
            </div>
            <div class="auth-field">
              <label for="signin-password" data-th="รหัสผ่าน" data-en="PASSWORD">รหัสผ่าน</label>
              <input id="signin-password" name="password" type="password" required placeholder="••••••••" data-th-placeholder="••••••••" data-en-placeholder="••••••••" />
            </div>
            <button type="submit" class="auth-submit" data-th="เข้าสู่ระบบ" data-en="SIGN IN">เข้าสู่ระบบ</button>
          </form>
        </div>
        <div class="auth-footer">
          <span data-th="ยังไม่มีบัญชี?" data-en="Don't have an account?">ยังไม่มีบัญชี?</span>
          <button type="button" class="auth-switch" data-switch="signup" data-th="สมัครสมาชิก" data-en="Sign Up">สมัครสมาชิก</button>
        </div>
      </div>

      <!-- Sign Up View -->
      <div id="auth-signup-view" class="auth-hidden">
        <div class="auth-header">
          <div>
            <h2 class="auth-title" data-th="สมัครสมาชิก" data-en="Sign Up">สมัครสมาชิก</h2>
            <p class="auth-subtitle" data-th="สร้างบัญชีเพื่อเริ่มใช้งาน" data-en="Create an account to get started">สร้างบัญชีเพื่อเริ่มใช้งาน</p>
          </div>
          <button type="button" class="auth-close" aria-label="Close">&times;</button>
        </div>
        <div class="auth-body">
          <div id="signup-popup-error" class="signin-popup-error" role="alert" aria-live="assertive"></div>
          <form method="POST" action="./auth_process.php">
            <input type="hidden" name="action" value="signup" />
            <?= csrf_input() ?>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-4">
              <div class="auth-field">
                <label for="signup-name" data-th="ชื่อ-นามสกุล" data-en="FULL NAME">ชื่อ-นามสกุล</label>
                <input id="signup-name" name="name" type="text" required placeholder="กรอกชื่อ-นามสกุล" data-th-placeholder="กรอกชื่อ-นามสกุล" data-en-placeholder="Enter your full name" />
              </div>
              <div class="auth-field">
                <label for="signup-student-id" data-th="รหัสนักศึกษา" data-en="STUDENT ID">รหัสนักศึกษา</label>
                <input id="signup-student-id" name="student_id" type="text" required placeholder="ระบุรหัสนักศึกษา 12 หลัก" data-th-placeholder="ระบุรหัสนักศึกษา 12 หลัก" data-en-placeholder="Enter 12-digit Student ID" />
              </div>
            </div>
            <div class="auth-field">
              <label for="signup-email" data-th="อีเมล" data-en="EMAIL">อีเมล</label>
              <input id="signup-email" name="email" type="email" required placeholder="example@rmuti.ac.th" data-th-placeholder="example@rmuti.ac.th" data-en-placeholder="example@rmuti.ac.th" />
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-4">
              <div class="auth-field">
                <label for="signup-password" data-th="รหัสผ่าน" data-en="PASSWORD">รหัสผ่าน</label>
                <input id="signup-password" name="password" type="password" required minlength="8" placeholder="อย่างน้อย 8 ตัวอักษร" data-th-placeholder="อย่างน้อย 8 ตัวอักษร" data-en-placeholder="At least 8 characters" />
              </div>
              <div class="auth-field">
                <label for="signup-confirm-password" data-th="ยืนยันรหัสผ่าน" data-en="CONFIRM PASSWORD">ยืนยันรหัสผ่าน</label>
                <input id="signup-confirm-password" name="confirm_password" type="password" required minlength="8" placeholder="••••••••" data-th-placeholder="••••••••" data-en-placeholder="••••••••" />
              </div>
            </div>
            <p style="margin-top:-6px;margin-bottom:12px;font-size:12px;color:var(--text-muted);" data-th="รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร" data-en="Password must be at least 8 characters">รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร</p>
            <div class="mb-4 flex items-start gap-2.5 px-1">
              <input type="checkbox" name="pdpa_consent" id="signup-pdpa-consent" required class="mt-[3px] h-4 w-4 shrink-0 cursor-pointer rounded border-[#C8CCCF] text-[var(--mt-red)] focus:ring-[var(--mt-red)]" />
              <label for="signup-pdpa-consent" class="mb-0 block cursor-pointer text-[12px] sm:text-[13px] font-normal normal-case leading-relaxed tracking-normal text-[var(--text-primary)]"
                data-th='ข้าพเจ้ายินยอมให้จัดเก็บและเผยแพร่ข้อมูลตาม <a href="#" class="open-privacy-notice font-semibold text-[var(--mt-red)] hover:underline">นโยบายความเป็นส่วนตัว</a> และยอมรับ <a href="#" class="open-terms-notice font-semibold text-[var(--mt-red)] hover:underline">ข้อตกลงและเงื่อนไขการใช้งาน</a>'
                data-en='I agree to the storage and dissemination of information according to the <a href="#" class="open-privacy-notice font-semibold text-[var(--mt-red)] hover:underline">Privacy Policy</a> and accept the <a href="#" class="open-terms-notice font-semibold text-[var(--mt-red)] hover:underline">Terms and Conditions of Use</a>'>
                ข้าพเจ้ายินยอมให้จัดเก็บและเผยแพร่ข้อมูลตาม <a href="#" class="open-privacy-notice font-semibold text-[var(--mt-red)] hover:underline">นโยบายความเป็นส่วนตัว</a> และยอมรับ <a href="#" class="open-terms-notice font-semibold text-[var(--mt-red)] hover:underline">ข้อตกลงและเงื่อนไขการใช้งาน</a>
              </label>
            </div>
            <button type="submit" class="auth-submit" data-th="สมัครสมาชิก" data-en="SIGN UP">สมัครสมาชิก</button>
          </form>
        </div>
        <div class="auth-footer">
          <span data-th="มีบัญชีอยู่แล้ว?" data-en="Already have an account?">มีบัญชีอยู่แล้ว?</span>
          <button type="button" class="auth-switch" data-switch="signin" data-th="เข้าสู่ระบบ" data-en="Sign In">เข้าสู่ระบบ</button>
        </div>
      </div>

      <!-- Success View -->
      <div id="auth-success-view" class="auth-hidden relative p-6 sm:p-8 text-center">
        <button type="button" class="auth-close absolute right-4 top-4 text-gray-400 hover:text-gray-600 dark:hover:text-white text-2xl transition-colors" aria-label="Close">&times;</button>
        
        <div class="mx-auto mb-5 flex h-20 w-20 items-center justify-center rounded-full bg-emerald-50 border-4 border-emerald-100 text-emerald-500 shadow-lg shadow-emerald-500/10 dark:bg-emerald-950/60 dark:border-emerald-800/80 dark:text-emerald-400">
          <svg class="h-10 w-10 animate-[bounce_1s_ease-in-out_1]" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
          </svg>
        </div>

        <h3 class="text-2xl font-extrabold text-gray-900 dark:text-white tracking-tight font-display" data-th="สมัครสมาชิกสำเร็จ!" data-en="Registration Successful!">สมัครสมาชิกสำเร็จ!</h3>
        
        <p class="mt-2 text-sm leading-relaxed text-gray-600 dark:text-gray-300 max-w-xs mx-auto" data-th="ระบบได้สร้างบัญชีของคุณเรียบร้อยแล้ว<br />คุณสามารถเข้าสู่ระบบเพื่อเริ่มใช้งานได้ทันที" data-en="Your account has been created successfully.<br />You can now sign in to start using the system.">
          ระบบได้สร้างบัญชีของคุณเรียบร้อยแล้ว<br />คุณสามารถเข้าสู่ระบบเพื่อเริ่มใช้งานได้ทันที
        </p>

        <div class="mt-6">
          <button type="button" id="btn-go-signin" class="inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl bg-[#D32F2F] text-sm font-bold uppercase tracking-wider text-white shadow-md transition-all duration-200 hover:bg-[#B71C1C] hover:shadow-[0_4px_14px_rgba(211,47,47,0.35)] active:scale-[0.99]" data-th="ไปหน้าเข้าสู่ระบบ" data-en="Go to Sign In">
            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" />
            </svg>
            <span data-th="ไปหน้าเข้าสู่ระบบ" data-en="Go to Sign In">ไปหน้าเข้าสู่ระบบ</span>
          </button>
        </div>
      </div>
    </div>
  </div>

  <script>
    (function () {
      const body = document.body;
      const overlay = document.getElementById('auth-modal-overlay');
      const signInView = document.getElementById('auth-signin-view');
      const signUpView = document.getElementById('auth-signup-view');
      const successView = document.getElementById('auth-success-view');
      const openSignInBtn = document.getElementById('btn-open-signin');
      const openSignUpBtn = document.getElementById('btn-open-signup');
      const goSignInBtn = document.getElementById('btn-go-signin');
      const closeButtons = document.querySelectorAll('.auth-close');
      const switchButtons = document.querySelectorAll('.auth-switch');
      const flashBox = document.getElementById('flash-message');
      const signInPopupError = document.getElementById('signin-popup-error');
      const signUpPopupError = document.getElementById('signup-popup-error');

      const errorMessages = {
        invalid_credentials: 'Invalid email or password.',
        signin_invalid_credentials: 'Invalid email or password.',
        login_failed: 'Sign in failed. Please try again.',
        signin_failed: 'Sign in failed. Please try again.',
        email_exists: 'This email is already in use.',
        signup_failed: 'Sign up failed. Please try again.',
        password_too_short: 'Password must be at least 8 characters.',
        password_mismatch: 'Passwords do not match.',
        registration_closed: 'ขออภัย ระบบปิดรับสมัครนักศึกษาใหม่ชั่วคราว',
        missing_fields: 'Please fill in all required fields.',
        signin_missing_fields: 'Please fill in all required fields.',
        invalid_email: 'Invalid email format.',
        signin_invalid_email: 'Invalid email format.',
        invalid_csrf: 'Session security token is invalid or expired. Please try again.'
      };

      function showFlashMessage() {
        if (!body) return;
        const err = body.dataset.error || '';
        const signInPopupErrors = ['invalid_credentials', 'login_failed', 'signin_invalid_credentials', 'signin_failed', 'signin_missing_fields', 'signin_invalid_email'];
        const signUpPopupErrors = ['registration_closed', 'email_exists', 'signup_failed', 'password_mismatch', 'password_too_short', 'missing_fields', 'invalid_email'];

        if (signInPopupErrors.indexOf(err) !== -1) {
          const msg = errorMessages[err] || 'Sign in failed. Please try again.';
          if (signInPopupError) {
            signInPopupError.textContent = msg;
            signInPopupError.classList.add('show');
          }
          if (signUpPopupError) {
            signUpPopupError.textContent = '';
            signUpPopupError.classList.remove('show');
          }
          if (flashBox) {
            flashBox.className = 'flash-message';
            flashBox.textContent = '';
          }
          return;
        }

        if (signUpPopupErrors.indexOf(err) !== -1) {
          const msg = errorMessages[err] || 'Sign up is currently unavailable.';
          if (signUpPopupError) {
            signUpPopupError.textContent = msg;
            signUpPopupError.classList.add('show');
          }
          if (signInPopupError) {
            signInPopupError.textContent = '';
            signInPopupError.classList.remove('show');
          }
          if (flashBox) {
            flashBox.className = 'flash-message';
            flashBox.textContent = '';
          }
          return;
        }

        if (signInPopupError) {
          signInPopupError.textContent = '';
          signInPopupError.classList.remove('show');
        }
        if (signUpPopupError) {
          signUpPopupError.textContent = '';
          signUpPopupError.classList.remove('show');
        }

        if (flashBox && err && errorMessages[err]) {
          flashBox.textContent = errorMessages[err];
          flashBox.className = 'flash-message show flash-error';
          return;
        }
      }

      function setMode(mode) {
        if (!signInView || !signUpView || !successView) return;
        if (mode === 'signup') {
          if (signInPopupError) {
            signInPopupError.textContent = '';
            signInPopupError.classList.remove('show');
          }
          successView.classList.add('auth-hidden');
          signInView.classList.add('auth-hidden');
          signUpView.classList.remove('auth-hidden');
        } else if (mode === 'success') {
          if (signInPopupError) {
            signInPopupError.textContent = '';
            signInPopupError.classList.remove('show');
          }
          if (signUpPopupError) {
            signUpPopupError.textContent = '';
            signUpPopupError.classList.remove('show');
          }
          signInView.classList.add('auth-hidden');
          signUpView.classList.add('auth-hidden');
          successView.classList.remove('auth-hidden');
        } else {
          if (signUpPopupError) {
            signUpPopupError.textContent = '';
            signUpPopupError.classList.remove('show');
          }
          successView.classList.add('auth-hidden');
          signUpView.classList.add('auth-hidden');
          signInView.classList.remove('auth-hidden');
        }
      }

      function openModal(mode) {
        if (!overlay) return;
        setMode(mode || 'signin');
        overlay.classList.add('open');
        overlay.setAttribute('aria-hidden', 'false');
        requestAnimationFrame(function () {
          overlay.classList.add('is-visible');
        });
      }

      function closeModal() {
        if (!overlay) return;
        overlay.classList.remove('is-visible');
        window.setTimeout(function () {
          overlay.classList.remove('open');
          overlay.setAttribute('aria-hidden', 'true');
        }, 300);
      }

      if (openSignInBtn) openSignInBtn.addEventListener('click', function () { openModal('signin'); });
      if (openSignUpBtn) openSignUpBtn.addEventListener('click', function () { openModal('signup'); });
      if (goSignInBtn) {
        goSignInBtn.addEventListener('click', function () { setMode('signin'); });
      }

      closeButtons.forEach(function (btn) {
        btn.addEventListener('click', closeModal);
      });

      if (overlay) {
        overlay.addEventListener('click', function (e) {
          if (e.target === overlay) closeModal();
        });
      }

      switchButtons.forEach(function (btn) {
        btn.addEventListener('click', function () {
          setMode(btn.dataset.switch || 'signin');
        });
      });

      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && overlay && overlay.classList.contains('open')) {
          closeModal();
        }
      });

      showFlashMessage();
      const initialModal = body ? (body.dataset.initialModal || '') : '';
      const successStatus = body ? (body.dataset.success || '') : '';
      if (successStatus === 'signup_success') {
        openModal('success');
        return;
      }
      if (initialModal === 'signin' || initialModal === 'signup') {
        openModal(initialModal);
      }
    })();
  </script>

  <!-- Developer Team & Policy Modal Helpers -->
  <script>
    (function () {
      var privacyHtmlTh = '<div style="text-align:left;line-height:1.65;font-size:14px;">'
        + '<p style="margin:0 0 12px;"><strong>1.</strong> วัตถุประสงค์การเก็บรวบรวมข้อมูล: ระบบจัดเก็บข้อมูลส่วนบุคคลเพื่อใช้ในการยืนยันตัวตนผู้จัดทำผลงาน จัดทำคลังข้อมูลวิทยานิพนธ์ประจำสาขาวิชาเทคโนโลยีมัลติมีเดีย และใช้เป็นข้อมูลอ้างอิงทางวิชาการ</p>'
        + '<p style="margin:0 0 12px;"><strong>2.</strong> ข้อมูลที่มีการจัดเก็บ: ข้อมูลประจำตัว: ชื่อ-นามสกุล, รหัสนักศึกษา, อีเมลมหาวิทยาลัย, และรายชื่ออาจารย์ที่ปรึกษา | ข้อมูลผลงาน: ชื่อโครงงาน, บทคัดย่อ, ไฟล์รูปภาพปก, ไฟล์วิดีโอ, และไฟล์สื่อมัลติมีเดียที่เกี่ยวข้อง | ข้อมูลการใช้งานระบบ: ประวัติการเข้าสู่ระบบ (Session Log) สำหรับผู้ดูแลระบบเพื่อความปลอดภัย</p>'
        + '<p style="margin:0 0 12px;"><strong>3.</strong> ระยะเวลาการจัดเก็บ: ข้อมูลจะถูกจัดเก็บไว้ตลอดระยะเวลาที่ระบบคลังผลงานเปิดให้บริการเพื่อประโยชน์ทางการศึกษาและการสืบค้นย้อนหลัง</p>'
        + '<p style="margin:0 0 12px;"><strong>4.</strong> การเปิดเผยข้อมูล: ข้อมูลชื่อผู้จัดทำ บทคัดย่อ และไฟล์สื่อมัลติมีเดียจะถูกแสดงผลบนหน้าเว็บไซต์สาธารณะ เพื่อเผยแพร่ผลงานสู่บุคคลภายนอกตามวัตถุประสงค์ของคลังข้อมูล</p>'
        + '<p style="margin:0;"><strong>5.</strong> สิทธิของเจ้าของข้อมูล: ผู้ใช้งานมีสิทธิ์ขอเข้าถึง ขอปรับปรุงข้อมูลให้ถูกต้อง หรือแจ้งลบข้อมูลส่วนบุคคล/ผลงานของตนเองได้ โดยติดต่อผ่านผู้ดูแลระบบหลังบ้านหรือสาขาวิชา</p>'
        + '</div>';

      var privacyHtmlEn = '<div style="text-align:left;line-height:1.65;font-size:14px;">'
        + '<p style="margin:0 0 12px;"><strong>1. Purpose of Data Collection:</strong> The system collects personal data to verify the identity of project creators, curate the thesis repository for the Department of Multimedia Technology, and serve as academic references.</p>'
        + '<p style="margin:0 0 12px;"><strong>2. Collected Data:</strong> Identity Information: Full name, Student ID, University email, and Advisor name(s) | Project Information: Project title, Abstract, Cover image, Video, and related multimedia files | System Usage: Admin access and session logs for security purposes.</p>'
        + '<p style="margin:0 0 12px;"><strong>3. Retention Period:</strong> Data will be retained throughout the operational period of the repository system for educational purposes and retrospective search.</p>'
        + '<p style="margin:0 0 12px;"><strong>4. Data Disclosure:</strong> Author names, abstracts, and multimedia files will be published publicly on the website according to the objectives of the repository.</p>'
        + '<p style="margin:0;"><strong>5. Data Subject Rights:</strong> Users have the right to access, update, or request deletion of their personal data or projects by contacting system administrators or the department.</p>'
        + '</div>';

      var termsHtmlTh = '<div style="text-align:left;line-height:1.65;font-size:14px;">'
        + '<p style="margin:0 0 12px;"><strong>1.</strong> การรับรองสิทธิ์ในผลงาน: ผู้ส่งผลงานต้องรับรองว่าผลงานมัลติมีเดีย (วิดีโอ, แอนิเมชัน, เกม, หรือสื่อปฏิสัมพันธ์) ที่นำเข้าสู่ระบบ เป็นผลงานที่สร้างสรรค์ขึ้นจริง และไม่ละเมิดลิขสิทธิ์ เครื่องหมายการค้า หรือสิทธิในทรัพย์สินทางปัญญาของบุคคลอื่น</p>'
        + '<p style="margin:0 0 12px;"><strong>2.</strong> สิทธิ์ในการเผยแพร่: ผู้ส่งผลงานตกลงยินยอมให้สาขาวิชาเทคโนโลยีมัลติมีเดีย นำผลงาน ภาพประกอบ และข้อมูลจำเพาะ (Metadata) ไปจัดแสดง เผยแพร่ หรือใช้เป็นกรณีศึกษาเพื่อประโยชน์ทางการเรียนการสอนได้</p>'
        + '<p style="margin:0;"><strong>3.</strong> ข้อห้ามในการนำเข้าข้อมูล: ห้ามอัปโหลดไฟล์ที่มีเนื้อหาขัดต่อกฎหมาย ละเมิดความเป็นส่วนตัว มีไวรัสแฝง หรือมีเนื้อหาที่ไม่เหมาะสมเข้าสู่ระบบฐานข้อมูล</p>'
        + '</div>';

      var termsHtmlEn = '<div style="text-align:left;line-height:1.65;font-size:14px;">'
        + '<p style="margin:0 0 12px;"><strong>1. Verification of Rights:</strong> Submitters must certify that multimedia works (videos, animations, games, or interactive media) uploaded to the system are genuinely created and do not infringe on copyrights, trademarks, or intellectual property rights of others.</p>'
        + '<p style="margin:0 0 12px;"><strong>2. Publication Rights:</strong> Submitters agree to allow the Department of Multimedia Technology to showcase, publish, or use the works, illustrations, and metadata as case studies for educational purposes.</p>'
        + '<p style="margin:0;"><strong>3. Prohibitions:</strong> It is strictly prohibited to upload files with illegal content, privacy violations, viruses, or inappropriate materials to the system database.</p>'
        + '</div>';

      var footerPrivacyHtmlTh = '<div style="text-align:left;font-size:14px;line-height:1.6;">'
        + '<strong>1. บทนำ:</strong> เว็บไซต์นี้ให้ความสำคัญกับการคุ้มครองข้อมูลส่วนบุคคลของผู้ใช้งานทุกกลุ่ม ได้แก่ ผู้รับชมทั่วไป นักศึกษา และอาจารย์ผู้ดูแลระบบ เพื่อให้เป็นไปตามพระราชบัญญัติคุ้มครองข้อมูลส่วนบุคคล (PDPA)<br><br>'
        + '<strong>2. ข้อมูลที่เราเก็บรวบรวม:</strong><br>'
        + '- <strong>ผู้รับชมทั่วไป:</strong> ระบบไม่มีการบังคับกรอกข้อมูลส่วนบุคคล สามารถเข้าถึงและรับชมผลงานได้ทันที<br>'
        + '- <strong>นักศึกษา/ผู้ส่งผลงาน:</strong> เก็บข้อมูลชื่อ-นามสกุล, รหัสนักศึกษา, อีเมล, รายชื่ออาจารย์ที่ปรึกษา, และรายละเอียดของผลงานมัลติมีเดีย<br>'
        + '- <strong>อาจารย์/ผู้ดูแลระบบ:</strong> เก็บข้อมูลบัญชีผู้ใช้งาน และประวัติการจัดการข้อมูลหลังบ้าน (Admin Log)<br><br>'
        + '<strong>3. วัตถุประสงค์ในการประมวลผลข้อมูล:</strong><br>'
        + '- เพื่อจัดทำฐานข้อมูลกลางสำหรับรวบรวม คัดกรอง และสืบค้นผลงานวิทยานิพนธ์ประจำสาขาวิชา<br>'
        + '- เพื่อแสดงผลสื่อมัลติมีเดีย (Video, Animation, Game, Interactive) ผ่านหน้าเว็บเบราว์เซอร์<br>'
        + '- เพื่อป้องกันการแอบอ้างสิทธิ์และการเข้าถึงระบบหลังบ้านโดยไม่ได้รับอนุญาต<br><br>'
        + '<strong>4. การรักษาความปลอดภัยของข้อมูล:</strong><br>'
        + '- รหัสผ่านของผู้ใช้งานทุกบัญชีจะถูกเข้ารหัส (Password Encryption) ก่อนบันทึกลงฐานข้อมูล MySQL<br>'
        + '- มีระบบตรวจสอบสิทธิ์การเข้าถึงหน้าจัดการข้อมูลหลังบ้าน (Admin Access) เพื่อป้องกันผู้ไม่มีส่วนเกี่ยวข้อง<br><br>'
        + '<strong>5. การติดต่อผู้ดูแลระบบ:</strong> หากต้องการสอบถาม เปลี่ยนแปลง หรือลบข้อมูลส่วนบุคคล สามารถติดต่อได้ที่ <a href="https://www.facebook.com/multimedia.rmuti" target="_blank" rel="noopener noreferrer" style="color:#D32F2F;text-decoration:underline;font-weight:600;">สาขาวิชาเทคโนโลยีมัลติมีเดีย คณะสถาปัตยกรรมศาสตร์และศิลปกรรมสร้างสรรค์ มทร.อีสาน</a>'
        + '</div>';

      var footerPrivacyHtmlEn = '<div style="text-align:left;font-size:14px;line-height:1.6;">'
        + '<strong>1. Introduction:</strong> This website prioritizes personal data protection for all user groups, including visitors, students, and administrators, in compliance with the Personal Data Protection Act (PDPA).<br><br>'
        + '<strong>2. Data We Collect:</strong><br>'
        + '- <strong>Visitors:</strong> No personal data is required; visitors can browse and view works immediately.<br>'
        + '- <strong>Students/Submitters:</strong> Full name, student ID, university email, advisor names, and multimedia project details.<br>'
        + '- <strong>Instructors/Admins:</strong> User account credentials and administrative activity logs (Admin Log).<br><br>'
        + '<strong>3. Purposes of Data Processing:</strong><br>'
        + '- To maintain a central repository for collecting, screening, and exploring departmental thesis projects.<br>'
        + '- To showcase multimedia (Video, Animation, Game, Interactive) via web browsers.<br>'
        + '- To prevent unauthorized access and protect account security.<br><br>'
        + '<strong>4. Data Security:</strong><br>'
        + '- User passwords are encrypted before being stored in MySQL database.<br>'
        + '- Access control verification is enforced for administrative management pages.<br><br>'
        + '<strong>5. Contacting Administrators:</strong> For inquiries, updates, or deletion requests regarding personal data, contact the <a href="https://www.facebook.com/multimedia.rmuti" target="_blank" rel="noopener noreferrer" style="color:#D32F2F;text-decoration:underline;font-weight:600;">Department of Multimedia Technology, Faculty of Architecture and Creative Arts, RMUTI</a>.'
        + '</div>';

      window.openPolicySwal = function (titleTh, titleEn, htmlTh, htmlEn) {
        var isDark = document.documentElement.classList.contains('dark');
        var lang = (document.documentElement.lang === 'en' || localStorage.getItem('lang') === 'en') ? 'en' : 'th';
        var title = lang === 'en' ? titleEn : titleTh;
        var html = lang === 'en' ? htmlEn : htmlTh;
        var confirmBtn = lang === 'en' ? 'OK' : 'ตกลง';
        if (typeof Swal !== 'undefined') {
          Swal.fire({
            title: title,
            html: html,
            confirmButtonText: confirmBtn,
            confirmButtonColor: '#D32F2F',
            width: '640px',
            background: isDark ? '#1E1E1E' : '#FFFFFF',
            color: isDark ? '#F3F4F6' : '#111827',
            customClass: {
              confirmButton: 'rounded-xl font-bold px-6 py-2.5 shadow-md'
            }
          });
        }
      };

      window.openDevTeamModal = function () {
        var isDark = document.documentElement.classList.contains('dark');
        var devTeamHtml = '<div style="text-align:left; font-family: system-ui, -apple-system, sans-serif;">'
          + '<div style="text-align:center; margin-bottom:20px;">'
          + '  <div style="display:inline-flex; align-items:center; justify-content:center; width:48px; height:48px; border-radius:16px; background:rgba(211, 47, 47, 0.12); color:#D32F2F; margin-bottom:10px;">'
          + '    <svg style="width:24px; height:24px;" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" /></svg>'
          + '  </div>'
          + '  <div style="font-size:10px; font-weight:800; tracking:0.18em; text-transform:uppercase; color:#D32F2F; letter-spacing:1.5px;">RMUTI MULTIMEDIA TECHNOLOGY</div>'
          + '  <h3 style="font-size:18px; font-weight:700; margin:4px 0 0; color:inherit;">ทีมผู้พัฒนาระบบ (Developer Team)</h3>'
          + '</div>'
          + '<div style="display:flex; flex-direction:column; gap:12px; margin-bottom:20px;">'
          + '  <div style="border:1px solid ' + (isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.08)') + '; border-radius:14px; padding:16px; background:' + (isDark ? '#262626' : '#F9FAFB') + ';">'
          + '    <div style="margin-bottom:4px;">'
          + '      <span style="font-size:15px; font-weight:700; color:inherit;">Patipat Sornchai</span>'
          + '    </div>'
          + '    <div style="font-size:12px; color:#D32F2F; font-weight:600; margin-bottom:6px;">UX/UI Designer, Tester & Co-Developer</div>'
          + '    <div style="font-size:11.5px; color:' + (isDark ? '#9CA3AF' : '#6B7280') + '; display:flex; align-items:center; gap:5px;">'
          + '      <svg style="width:13px; height:13px; flex-shrink:0; opacity:0.8;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>'
          + '      <a href="mailto:Patipat.fillm@gmail.com" style="color:inherit; text-decoration:none;">Patipat.fillm@gmail.com</a>'
          + '    </div>'
          + '  </div>'
          + '  <div style="border:1px solid ' + (isDark ? 'rgba(255,255,255,0.1)' : 'rgba(0,0,0,0.08)') + '; border-radius:14px; padding:16px; background:' + (isDark ? '#262626' : '#F9FAFB') + ';">'
          + '    <div style="margin-bottom:4px;">'
          + '      <span style="font-size:15px; font-weight:700; color:inherit;">Nut Asavasapphavat</span>'
          + '    </div>'
          + '    <div style="font-size:12px; color:#D32F2F; font-weight:600; margin-bottom:6px;">Full-Stack Developer & Database Architect</div>'
          + '    <div style="font-size:11.5px; color:' + (isDark ? '#9CA3AF' : '#6B7280') + '; display:flex; align-items:center; gap:5px;">'
          + '      <svg style="width:13px; height:13px; flex-shrink:0; opacity:0.8;" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/></svg>'
          + '      <a href="mailto:Nutaomsin23@gmail.com" style="color:inherit; text-decoration:none;">Nutaomsin23@gmail.com</a>'
          + '    </div>'
          + '  </div>'
          + '</div>'
          + '<div style="text-align:center; font-size:11px; color:#9CA3AF; border-top:1px solid ' + (isDark ? '#333' : '#E5E7EB') + '; padding-top:12px; line-height:1.5;">'
          + '  สาขาวิชาเทคโนโลยีมัลติมีเดีย คณะสถาปัตยกรรมศาสตร์และศิลปกรรมสร้างสรรค์<br>มหาลัยเทคโนโลยีราชมงคลอีสาน (RMUTI) © 2026'
          + '</div>'
          + '</div>';

        if (typeof Swal !== 'undefined') {
          Swal.fire({
            html: devTeamHtml,
            confirmButtonText: 'ปิด (Close)',
            confirmButtonColor: '#D32F2F',
            width: '460px',
            background: isDark ? '#1E1E1E' : '#FFFFFF',
            color: isDark ? '#F3F4F6' : '#111827',
            customClass: {
              confirmButton: 'rounded-xl font-bold px-6 py-2.5 shadow-md'
            }
          });
        }
      };

      document.addEventListener('click', function (e) {
        var devLink = e.target.closest('.open-dev-team');
        if (devLink) {
          e.preventDefault();
          e.stopPropagation();
          window.openDevTeamModal();
          return;
        }

        var privacyLink = e.target.closest('.open-privacy-notice');
        if (privacyLink) {
          e.preventDefault();
          e.stopPropagation();
          window.openPolicySwal('นโยบายการคุ้มครองข้อมูลส่วนบุคคล (PDPA Privacy Notice)', 'PDPA Privacy Notice', privacyHtmlTh, privacyHtmlEn);
          return;
        }

        var termsLink = e.target.closest('.open-terms-notice');
        if (termsLink) {
          e.preventDefault();
          e.stopPropagation();
          window.openPolicySwal('ข้อตกลงและเงื่อนไขการนำส่งผลงาน (Submission Terms & Conditions)', 'Submission Terms & Conditions', termsHtmlTh, termsHtmlEn);
          return;
        }

        var footerPrivacyLink = e.target.closest('.open-footer-privacy');
        if (footerPrivacyLink) {
          e.preventDefault();
          window.openPolicySwal('นโยบายความเป็นส่วนตัวและคุ้มครองข้อมูลส่วนบุคคล (Privacy Policy)', 'Privacy Policy', footerPrivacyHtmlTh, footerPrivacyHtmlEn);
          return;
        }

        var footerTermsLink = e.target.closest('.open-footer-terms');
        if (footerTermsLink) {
          e.preventDefault();
          window.openPolicySwal('ข้อตกลงและเงื่อนไขการใช้งานเว็บไซต์ (Terms of Service)', 'Terms of Service', termsHtmlTh, termsHtmlEn);
          return;
        }
      });
    })();
  </script>
