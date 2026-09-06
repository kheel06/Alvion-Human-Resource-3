<?php if (isset($_SESSION['user_id'])): ?>
            </main>
        </div>
    </div>
<?php else: ?>
    </main>
<?php endif; ?>

<!-- Logout Loading Overlay -->
<div id="logoutLoadingOverlay" class="hidden fixed inset-0 bg-[#263238]/60 dark:bg-[#0F172A]/80 backdrop-blur-sm z-[9998] flex items-center justify-center transition-opacity duration-200 ease-out opacity-0" aria-hidden="true">
    <div data-logout-dialog class="bg-white dark:bg-[#1E293B] rounded-2xl shadow-2xl px-8 py-10 flex flex-col items-center text-center max-w-sm w-full mx-4 transform transition-all duration-200 ease-out scale-95 opacity-0">
        <div class="h-16 w-16 rounded-full bg-[#E3F2FD] dark:bg-[#1E40AF]/20 flex items-center justify-center mb-5">
            <svg class="animate-spin h-8 w-8 text-[#1E88E5] dark:text-[#38BDF8]" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" role="img" aria-label="Loading">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 0 1 8-8v4a4 4 0 0 0-4 4H4z"></path>
            </svg>
        </div>
        <p class="text-lg font-semibold text-[#263238] dark:text-[#E5E7EB]">Logging you out</p>
        <p class="text-sm text-[#607D8B] dark:text-[#94A3B8] mt-2">Please wait while we securely end your session.</p>
    </div>
</div>

<!-- Toast Container -->
<div id="toast-container" class="fixed right-4 flex flex-col items-end justify-start space-y-3 pointer-events-none z-50" style="max-width: 24rem;"></div>

<!-- Alert Modal (Flowbite) -->
<div id="alertModal" tabindex="-1" aria-hidden="true" class="hidden fixed inset-0 z-50 overflow-y-auto flex items-center justify-center p-4">
    <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" onclick="document.getElementById('alertModal').classList.add('hidden')"></div>
    <div class="relative bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-md w-full p-6">
        <div class="flex flex-col items-center text-center">
            <div id="alertModalIcon" class="mx-auto flex items-center justify-center h-12 w-12 rounded-full mb-4">
                <!-- Icon will be inserted here -->
            </div>
            <h3 id="alertModalTitle" class="text-lg font-semibold mb-2">
                <!-- Title will be inserted here -->
            </h3>
            <p id="alertModalMessage" class="text-sm text-gray-500 dark:text-gray-400 mb-6">
                <!-- Message will be inserted here -->
            </p>
            <div id="alertModalFooter" class="flex gap-3 w-full justify-center">
                <button id="alertModalOkBtn" type="button" class="px-4 py-2 text-sm font-medium text-white rounded-lg focus:ring-4 focus:outline-none">
                    OK
                </button>
                <button id="alertModalCancelBtn" type="button" class="hidden px-4 py-2 text-sm font-medium text-gray-500 bg-white dark:bg-gray-700 dark:text-gray-300 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-600 focus:ring-4 focus:outline-none focus:ring-gray-200 dark:focus:ring-gray-700">
                    Cancel
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Flowbite JS -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/flowbite/1.8.1/flowbite.min.js"></script>

<!-- Lucide Icons -->
<script src="https://unpkg.com/lucide@latest"></script>

<!-- Custom JS -->
<script src="<?php echo BASE_URL; ?>/assets/js/main.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/toast.js"></script>
<script src="<?php echo BASE_URL; ?>/assets/js/alert-modal.js"></script>
<script>
// Full Page Loader Management (logo only)
(function() {
    'use strict';
    
    const pageLoader = document.getElementById('pageLoader');
    
    if (!pageLoader) return;
    
    // Hide loader with fade out
    function hideLoader() {
        document.body.style.overflow = '';
        document.documentElement.style.overflow = '';
        
        if (pageLoader) {
            pageLoader.style.opacity = '0';
            setTimeout(() => {
                if (pageLoader) {
                    pageLoader.style.display = 'none';
                    setTimeout(() => {
                        if (pageLoader && pageLoader.parentNode) {
                            pageLoader.parentNode.removeChild(pageLoader);
                        }
                    }, 300);
                }
            }, 300);
        }
    }
    
    // Prevent body scrolling while loader is active
    document.body.style.overflow = 'hidden';
    document.documentElement.style.overflow = 'hidden';
    
    // Hide loader when page is ready
    if (document.readyState === 'complete') {
        setTimeout(hideLoader, 280);
    } else {
        window.addEventListener('load', function() {
            setTimeout(hideLoader, 280);
        });
        
        window.addEventListener('pageshow', function(event) {
            if (event.persisted) hideLoader();
            else setTimeout(hideLoader, 280);
        });
    }
    
    // Fallback: hide after max wait (5 seconds)
    setTimeout(function() {
        if (pageLoader && pageLoader.style.display !== 'none') {
            hideLoader();
        }
    }, 5000);
    
    // Show loader on navigation (for same-origin links)
    document.addEventListener('click', function(e) {
        const link = e.target.closest('a[href]');
        if (!link) return;
        
        const href = link.getAttribute('href');
        if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;
        if (link.target && link.target !== '_self') return;
        if (link.origin !== window.location.origin && !href.startsWith('/')) return;
        
        // Don't show loader for logout links (they have their own loader)
        if (link.hasAttribute('data-logout-trigger')) return;
        
        // Show loader (logo only) immediately if it exists and is hidden
        const currentLoader = document.getElementById('pageLoader');
        if (currentLoader && currentLoader.style.display === 'none') {
            currentLoader.style.display = 'flex';
            currentLoader.style.opacity = '1';
            document.body.style.overflow = 'hidden';
            document.documentElement.style.overflow = 'hidden';
        }
        // If loader was removed from DOM, the new page will have its own loader
    }, true); // Use capture phase to catch early
})();

document.addEventListener('DOMContentLoaded', function() {
    const logoutLinks = document.querySelectorAll('[data-logout-trigger="true"]');
    const overlay = document.getElementById('logoutLoadingOverlay');
    const overlayDialog = overlay ? overlay.querySelector('[data-logout-dialog]') : null;
    const toastContainer = document.getElementById('toast-container');
    
    // Position toast container below header
    function positionToastContainer() {
        if (toastContainer) {
            const header = document.querySelector('header');
            if (header) {
                const headerRect = header.getBoundingClientRect();
                const headerHeight = headerRect.height;
                toastContainer.style.top = (headerHeight + 8) + 'px'; // 8px spacing below header
            } else {
                // Fallback if header not found (public pages)
                toastContainer.style.top = '1rem';
            }
        }
    }
    
    // Set initial position
    positionToastContainer();
    
    // Update position on window resize (in case header height changes)
    window.addEventListener('resize', positionToastContainer);

    function showLogoutOverlay() {
        if (!overlay || overlay.dataset.visible === 'true') return;
        overlay.dataset.visible = 'true';
        overlay.classList.remove('hidden');
        overlay.setAttribute('aria-hidden', 'false');
        requestAnimationFrame(() => {
            overlay.classList.remove('opacity-0');
            if (overlayDialog) {
                overlayDialog.classList.remove('opacity-0');
                overlayDialog.classList.remove('scale-95');
            }
        });
    }

    logoutLinks.forEach(link => {
        link.addEventListener('click', function(event) {
            const targetUrl = this.getAttribute('href');
            if (!targetUrl) {
                return;
            }
            event.preventDefault();
            showLogoutOverlay();
            setTimeout(() => {
                window.location.href = targetUrl;
            }, 450);
        });
    });

});
</script>

<?php
// Show toast messages if any (using toast.js functions)
$toastMessages = [];
if (isset($_SESSION['success'])) {
    $toastMessages[] = ['type' => 'success', 'message' => $_SESSION['success']];
    unset($_SESSION['success']);
}
if (isset($_SESSION['error'])) {
    $toastMessages[] = ['type' => 'error', 'message' => $_SESSION['error']];
    unset($_SESSION['error']);
}
if (isset($_SESSION['warning'])) {
    $toastMessages[] = ['type' => 'warning', 'message' => $_SESSION['warning']];
    unset($_SESSION['warning']);
}
if (isset($_SESSION['info'])) {
    $toastMessages[] = ['type' => 'info', 'message' => $_SESSION['info']];
    unset($_SESSION['info']);
}

if (!empty($toastMessages)) {
    echo "<script>";
    echo "document.addEventListener('DOMContentLoaded', function() {";
    foreach ($toastMessages as $toast) {
        $escapedMessage = addslashes($toast['message']);
        $type = $toast['type'];
        // Use the appropriate toast function based on type
        if ($type === 'success') {
            echo "if (typeof showSuccess === 'function') { showSuccess('{$escapedMessage}'); } else if (typeof showToast === 'function') { showToast('success', '{$escapedMessage}'); }";
        } elseif ($type === 'error') {
            echo "if (typeof showError === 'function') { showError('{$escapedMessage}'); } else if (typeof showToast === 'function') { showToast('error', '{$escapedMessage}'); }";
        } elseif ($type === 'warning') {
            echo "if (typeof showWarning === 'function') { showWarning('{$escapedMessage}'); } else if (typeof showToast === 'function') { showToast('warning', '{$escapedMessage}'); }";
        } elseif ($type === 'info') {
            echo "if (typeof showInfo === 'function') { showInfo('{$escapedMessage}'); } else if (typeof showToast === 'function') { showToast('info', '{$escapedMessage}'); }";
        } else {
            echo "if (typeof showToast === 'function') { showToast('{$type}', '{$escapedMessage}'); }";
        }
    }
    echo "});";
    echo "</script>";
}
?>

</body>
</html>