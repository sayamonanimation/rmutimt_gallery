<?php
declare(strict_types=1);

require_once __DIR__ . '/config/session.php';
start_secure_session();
session_write_close();

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/project_file_proxy.php';

$projectId = (int) ($_GET['id'] ?? 0);
$project = project_proxy_load_authorized($pdo, $projectId);
if ($project === null) {
    http_response_code(404);
    echo 'Project not found.';
    exit;
}

$fileUrls = project_proxy_parse_file_urls((string) ($project['file_urls'] ?? ''));
$entry = $fileUrls['thesis_pdf'] ?? null;
if (!is_array($entry) || !project_proxy_entry_has_file($entry)) {
    http_response_code(404);
    echo 'Thesis PDF not found.';
    exit;
}

$directStreamUrl = project_proxy_resolve_stream_url($entry);
$fallbackProxyUrl = './serve_pdf.php?id=' . $projectId;
$pdfSourceUrl = ($directStreamUrl !== '') ? $directStreamUrl : $fallbackProxyUrl;
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="robots" content="noindex, nofollow">
  <title>Thesis PDF</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    #pdf-container canvas {
      -webkit-user-select: none;
      user-select: none;
      -webkit-touch-callout: none;
      pointer-events: auto;
    }
  </style>
</head>
<body class="min-h-screen bg-zinc-900 text-zinc-100 antialiased" oncontextmenu="return false;">
  <div class="sticky top-0 z-10 border-b border-zinc-800 bg-zinc-900/95 px-4 py-3 backdrop-blur">
    <div class="mx-auto flex max-w-5xl items-center justify-between gap-4">
      <div>
        <p class="text-[11px] font-semibold uppercase tracking-[0.18em] text-zinc-500">Read Only</p>
        <h1 class="text-lg font-semibold text-white">Thesis PDF</h1>
      </div>
      <p id="page-status" class="text-sm text-zinc-400">Loading...</p>
    </div>
  </div>

  <main
    id="pdf-container"
    class="mx-auto flex w-full max-w-5xl flex-col items-center gap-8 px-4 py-8"
    oncontextmenu="return false;"
  >
    <div id="pdf-loader" class="my-12 flex w-full max-w-md flex-col items-center gap-4 rounded-2xl border border-zinc-800 bg-zinc-900/90 p-6 text-center shadow-2xl backdrop-blur">
      <div class="flex items-center gap-3 text-red-500">
        <svg class="h-6 w-6 animate-spin shrink-0" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <span class="text-base font-bold text-white tracking-wide">กำลังโหลดเล่มวิทยานิพนธ์...</span>
      </div>

      <div class="w-full rounded-full bg-zinc-800 p-1 border border-zinc-700/60 shadow-inner">
        <div id="pdf-progress-bar" class="h-2.5 w-0 rounded-full bg-gradient-to-r from-red-600 to-rose-500 transition-all duration-200 shadow-sm shadow-red-500/50"></div>
      </div>

      <div class="flex w-full justify-between text-xs font-semibold text-zinc-400">
        <span id="pdf-progress-text">0.0 MB</span>
        <span id="pdf-progress-percent">0%</span>
      </div>
    </div>
  </main>

  <div id="pdf-error" class="mx-auto hidden max-w-2xl px-4 py-16 text-center text-red-300"></div>

  <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>
  <script>
    (function () {
      var pdfSourceUrl = <?= json_encode($pdfSourceUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
      var fallbackProxyUrl = <?= json_encode($fallbackProxyUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
      var container = document.getElementById('pdf-container');
      var pageStatus = document.getElementById('page-status');
      var errorBox = document.getElementById('pdf-error');

      if (!window.pdfjsLib) {
        pageStatus.textContent = 'Failed to load PDF viewer.';
        return;
      }

      pdfjsLib.GlobalWorkerOptions.workerSrc =
        'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';

      function blockContextMenu(event) {
        event.preventDefault();
        return false;
      }

      document.addEventListener('contextmenu', blockContextMenu);
      container.addEventListener('contextmenu', blockContextMenu);

      function showError(message) {
        var loaderEl = document.getElementById('pdf-loader');
        if (loaderEl && loaderEl.parentNode) {
          loaderEl.parentNode.removeChild(loaderEl);
        }
        pageStatus.textContent = 'Unable to open PDF';
        errorBox.textContent = message;
        errorBox.classList.remove('hidden');
      }

      function computeDisplayScale(page, maxWidth) {
        var baseViewport = page.getViewport({ scale: 1 });
        var targetWidth = Math.min(maxWidth, baseViewport.width);
        return targetWidth / baseViewport.width;
      }

      var pdfDoc = null;
      var totalPages = 0;
      var renderedPages = {};
      var renderingPages = {};

      function renderSinglePage(pageNumber, wrapper) {
        if (renderedPages[pageNumber] || renderingPages[pageNumber] || !pdfDoc) {
          return Promise.resolve();
        }
        renderingPages[pageNumber] = true;

        return pdfDoc.getPage(pageNumber).then(function (page) {
          var maxWidth = Math.min(container.clientWidth || window.innerWidth - 32, 900);
          var displayScale = computeDisplayScale(page, maxWidth);
          var viewport = page.getViewport({ scale: displayScale });
          var outputScale = Math.max(window.devicePixelRatio || 1, 2.0);

          var canvas = document.createElement('canvas');
          canvas.width = Math.floor(viewport.width * outputScale);
          canvas.height = Math.floor(viewport.height * outputScale);
          canvas.style.width = '100%';
          canvas.style.maxWidth = '900px';
          canvas.style.height = 'auto';
          canvas.style.margin = '0 auto';
          canvas.style.display = 'block';
          canvas.setAttribute('draggable', 'false');
          canvas.addEventListener('contextmenu', blockContextMenu);
          canvas.addEventListener('dragstart', function (event) {
            event.preventDefault();
          });

          var context = canvas.getContext('2d', { alpha: false });
          if (!context) {
            delete renderingPages[pageNumber];
            return Promise.reject(new Error('Canvas is not supported.'));
          }
          context.imageSmoothingEnabled = true;
          context.imageSmoothingQuality = 'high';

          return page.render({
            canvasContext: context,
            viewport: viewport,
            transform: outputScale !== 1 ? [outputScale, 0, 0, outputScale, 0, 0] : null
          }).promise.then(function () {
            wrapper.innerHTML = '';
            wrapper.appendChild(canvas);
            renderedPages[pageNumber] = true;
            delete renderingPages[pageNumber];
          });
        }).catch(function (err) {
          delete renderingPages[pageNumber];
          console.error('Error rendering page ' + pageNumber, err);
        });
      }

      function loadPdfDocument(sourceUrl, isFallback) {
        var loaderEl = document.getElementById('pdf-loader');
        var progressBar = document.getElementById('pdf-progress-bar');
        var progressText = document.getElementById('pdf-progress-text');
        var progressPercent = document.getElementById('pdf-progress-percent');

        var loadingTask = pdfjsLib.getDocument({
          url: sourceUrl,
          withCredentials: false,
          disableRange: true,
          disableStream: false,
          disableAutoFetch: false
        });

        loadingTask.onProgress = function (data) {
          if (data && data.total > 0) {
            var percent = Math.min(100, Math.round((data.loaded / data.total) * 100));
            var mbLoaded = (data.loaded / (1024 * 1024)).toFixed(1);
            var mbTotal = (data.total / (1024 * 1024)).toFixed(1);
            if (progressBar) progressBar.style.width = percent + '%';
            if (progressPercent) progressPercent.textContent = percent + '%';
            if (progressText) progressText.textContent = mbLoaded + ' MB / ' + mbTotal + ' MB';
          } else if (data && data.loaded > 0) {
            var mbLoaded = (data.loaded / (1024 * 1024)).toFixed(1);
            if (progressText) progressText.textContent = mbLoaded + ' MB';
            if (progressPercent) progressPercent.textContent = 'กำลังโหลด...';
          }
        };

        loadingTask.promise.then(function (pdf) {
          if (loaderEl && loaderEl.parentNode) {
            loaderEl.parentNode.removeChild(loaderEl);
          }
          pdfDoc = pdf;
          totalPages = pdf.numPages;
          pageStatus.textContent = totalPages + ' page' + (totalPages === 1 ? '' : 's');

          // Create page placeholders
          var wrappers = [];
          for (var i = 1; i <= totalPages; i++) {
            (function (pageNum) {
              var wrapper = document.createElement('div');
              wrapper.id = 'pdf-page-' + pageNum;
              wrapper.dataset.pageNum = pageNum;
              wrapper.className = 'pdf-page-wrapper w-full max-w-[900px] min-h-[500px] rounded-sm bg-white p-2 shadow-2xl ring-1 ring-black/10 flex items-center justify-center';
              wrapper.setAttribute('oncontextmenu', 'return false;');
              wrapper.innerHTML = '<span class="text-xs text-zinc-400 font-medium">Page ' + pageNum + ' of ' + totalPages + '</span>';
              container.appendChild(wrapper);
              wrappers.push(wrapper);
            })(i);
          }

          // Render Page 1 IMMEDIATELY
          if (wrappers[0]) {
            renderSinglePage(1, wrappers[0]);
          }

          // Lazy load remaining pages on scroll using IntersectionObserver
          if ('IntersectionObserver' in window) {
            var observer = new IntersectionObserver(function (entries) {
              entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                  var pNum = parseInt(entry.target.dataset.pageNum, 10);
                  if (pNum && !renderedPages[pNum]) {
                    renderSinglePage(pNum, entry.target);
                  }
                }
              });
            }, {
              rootMargin: '400px 0px 400px 0px'
            });

            wrappers.forEach(function (w) {
              observer.observe(w);
            });
          } else {
            // Fallback if IntersectionObserver is not supported: render all sequentially
            var chain = Promise.resolve();
            wrappers.forEach(function (w, idx) {
              chain = chain.then(function () {
                return renderSinglePage(idx + 1, w);
              });
            });
          }
        }).catch(function (error) {
          if (!isFallback && fallbackProxyUrl && sourceUrl !== fallbackProxyUrl) {
            console.warn('Direct CDN load failed, trying local proxy fallback...', error);
            loadPdfDocument(fallbackProxyUrl, true);
          } else {
            showError(error && error.message ? error.message : 'Unable to render thesis PDF.');
          }
        });
      }

      loadPdfDocument(pdfSourceUrl, false);
    })();
  </script>
</body>
</html>
