<style id="site-dark-base">
  html.dark {
    --bg-primary: #1e1e1e;
    --bg-secondary: #121212;
    --text-primary: #F5F5F5;
    --text-muted: #A3A3A3;
    --border: #333333;
  }
  html.dark body {
    background-color: #121212 !important;
    color: #F5F5F5;
  }
  html.dark main {
    background-color: #121212;
  }
  html.dark header.sticky,
  html.dark .site-header {
    background: rgba(10, 10, 10, 0.96) !important;
    border-color: #262626 !important;
  }
  html.dark .form-input,
  html.dark .form-select,
  html.dark .form-textarea,
  html.dark .input,
  html.dark .settings-input {
    background-color: #1e1e1e !important;
    border-color: #374151 !important;
    color: #ffffff !important;
  }
  html.dark .form-input:focus,
  html.dark .form-select:focus,
  html.dark .form-textarea:focus,
  html.dark .input:focus,
  html.dark .settings-input:focus {
    border-color: var(--mt-red) !important;
    box-shadow: 0 0 0 3px rgba(211, 47, 47, 0.2) !important;
  }
  html.dark .upload-zone {
    background: #1e1e1e;
    border-color: #333333;
  }
  html.dark .file-list-card,
  html.dark .rounded-2xl.border,
  html.dark .rounded-xl.border {
    border-color: #333333;
  }
  html.dark .bg-white {
    --tw-bg-opacity: 1;
    background-color: rgb(30 30 30 / var(--tw-bg-opacity)) !important;
  }
  html.dark .bg-gray-50,
  html.dark .bg-\[\#F3F4F6\],
  html.dark .bg-\[\#F8F9FA\],
  html.dark .bg-\[\#FAFBFC\],
  html.dark .bg-\[\#F7F8FA\] {
    background-color: #262626 !important;
  }
  html.dark .bg-\[\#F9FAFB\],
  html.dark .bg-\[\#FAFAFA\] {
    background-color: #1e1e1e !important;
  }
  html.dark .border-gray-100,
  html.dark .border-gray-200,
  html.dark .border-\[\#E5E7EB\],
  html.dark .border-\[\#E2E4E8\],
  html.dark .border-\[\#E3E5E8\],
  html.dark .border-\[\#DFE2E6\],
  html.dark .border-\[\#DCE0E5\],
  html.dark .border-\[\#D7DCE2\],
  html.dark .border-\[\#D5D8DB\],
  html.dark .border-\[\#D0D5DD\] {
    border-color: #333333 !important;
  }
  html.dark .text-black,
  html.dark .text-gray-900,
  html.dark .text-gray-800,
  html.dark .text-\[\#111827\],
  html.dark .text-\[\#1E1E1E\],
  html.dark .text-\[\#202328\] {
    color: #F5F5F5 !important;
  }
  html.dark .text-gray-600,
  html.dark .text-\[\#374151\],
  html.dark .text-\[\#444\],
  html.dark .text-\[\#4B5563\],
  html.dark .text-\[\#555\],
  html.dark .text-\[\#555F6D\],
  html.dark .text-\[\#31343A\] {
    color: #D4D4D4 !important;
  }
  html.dark .text-gray-500,
  html.dark .text-\[\#6B7280\],
  html.dark .text-\[\#5F6368\],
  html.dark .text-\[\#8B919A\],
  html.dark .text-\[\#8A8F98\] {
    color: #A3A3A3 !important;
  }
  html.dark .text-\[\#9CA3AF\],
  html.dark .text-\[\#9AA0A6\],
  html.dark .text-\[\#A0A6AD\],
  html.dark .text-\[\#A0A6AE\],
  html.dark .text-\[\#A2A8AF\],
  html.dark .text-\[\#A6ADB6\],
  html.dark .text-\[\#9BA1A9\],
  html.dark .text-\[\#B1B7BF\],
  html.dark .text-\[\#B6BBC1\] {
    color: #737373 !important;
  }
  html.dark .selected-box,
  html.dark .options-menu {
    background-color: #1e1e1e !important;
    border-color: #333333 !important;
    color: #F5F5F5 !important;
  }
  html.dark .option-item {
    color: #D4D4D4 !important;
  }
  html.dark .option-item:hover {
    background-color: #262626 !important;
    color: #F5F5F5 !important;
  }
  html.dark .modal-inner,
  html.dark .modal-panel {
    background-color: #1e1e1e !important;
    color: #F5F5F5;
    border-color: #333333 !important;
  }
  html.dark .modal-panel h2,
  html.dark .modal-panel h3,
  html.dark .modal-inner h2,
  html.dark .modal-inner h3 {
    color: #ffffff;
  }
  html.dark .modal-panel p,
  html.dark .modal-inner p {
    color: #d1d5db;
  }
  html.dark .modal-panel input:not([type="file"]):not([type="hidden"]):not([type="checkbox"]):not([type="radio"]),
  html.dark .modal-panel select,
  html.dark .modal-panel textarea,
  html.dark .modal-inner input:not([type="file"]):not([type="hidden"]):not([type="checkbox"]):not([type="radio"]),
  html.dark .modal-inner select,
  html.dark .modal-inner textarea {
    background-color: #121212 !important;
    border-color: #374151 !important;
    color: #ffffff !important;
  }
  html.dark .swal2-popup {
    background-color: #1e1e1e !important;
    color: #ffffff !important;
    border: 1px solid #333333 !important;
  }
  html.dark .swal2-title,
  html.dark .swal2-html-container {
    color: #e5e7eb !important;
  }
  html.dark .swal2-icon.swal2-warning {
    border-color: #f59e0b !important;
    color: #f59e0b !important;
  }
  html.dark .swal2-icon.swal2-error {
    border-color: #ef4444 !important;
    color: #ef4444 !important;
  }
  html.dark .swal2-icon.swal2-success {
    border-color: #10b981 !important;
    color: #10b981 !important;
  }
  html.dark .project-row {
    border-color: #333333 !important;
  }
  html.dark .admin-content-card,
  html.dark .content-panel {
    background: #1e1e1e !important;
    border-color: #333333 !important;
  }
  html.dark hr.border-\[\#E5E7EB\] {
    border-color: #333333;
  }
  html.dark .hover\:bg-gray-50:hover,
  html.dark .hover\:bg-\[\#F8F9FA\]:hover,
  html.dark .hover\:bg-\[\#F8FAFC\]:hover {
    background-color: #333333 !important;
  }
  html.dark .hover\:bg-gray-100:hover {
    background-color: #333333 !important;
  }
  html.dark .hover\:text-gray-900:hover {
    color: #F5F5F5 !important;
  }
  html.dark .hover\:text-\[\#1E1E1E\]:hover {
    color: #F5F5F5 !important;
  }
  html.dark input::placeholder,
  html.dark textarea::placeholder {
    color: #737373 !important;
  }
  html.dark .font-display {
    color: inherit;
  }
  html.dark .searchbar-grid {
    background-color: #1e1e1e !important;
    border-color: #333333 !important;
  }
  html.dark .search-field input:not([type="checkbox"]) {
    color: #F3F4F6 !important;
  }
  html.dark .search-field input::placeholder {
    color: #9CA3AF !important;
  }
  html.dark .search-field select,
  html.dark .search-field .selected-box {
    color: #F3F4F6 !important;
  }
  html.dark .search-field select option {
    background-color: #1e1e1e !important;
    color: #F3F4F6 !important;
  }
  html.dark .search-field svg {
    color: #9CA3AF !important;
  }
  html.dark .options-menu {
    background-color: #1e1e1e !important;
    border-color: #333333 !important;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5) !important;
    z-index: 10050 !important;
  }
  html.dark .options-menu {
    background-color: #1e1e1e !important;
    border-color: #333333 !important;
    color: #E5E7EB !important;
    z-index: 10050 !important;
  }
  html.dark .option-item,
  html.dark .options-menu .option-item,
  html.dark .options-menu button {
    color: #E5E7EB !important;
  }
  html.dark .option-item:hover,
  html.dark .options-menu .option-item:hover,
  html.dark .options-menu button:hover {
    background-color: #262626 !important;
    color: #F87171 !important;
  }
  html.dark .option-item.bg-\[\#F9FAFB\],
  html.dark .option-item.dark\:bg-\[\#262626\],
  html.dark .options-menu .option-item.bg-\[\#F9FAFB\] {
    background-color: #262626 !important;
    color: #F87171 !important;
  }
</style>
