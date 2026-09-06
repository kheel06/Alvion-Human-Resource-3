/**
 * Session Timeout Manager
 * Handles automatic logout after inactivity with warning modal
 * 
 * Configuration:
 * - WARNING_TIME: Time before showing warning modal (default: 2 minutes)
 * - LOGOUT_TIME: Time before automatic logout (default: 30 seconds after warning)
 */

class SessionTimeoutManager {
    constructor(config = {}) {
        this.warningTime = config.warningTime || 2 * 60 * 1000; // 2 minutes in milliseconds
        this.logoutTime = config.logoutTime || 30 * 1000; // 30 seconds after warning
        this.checkInterval = config.checkInterval || 1000; // Check every second

        this.lastActivity = Date.now();
        this.warningShown = false;
        this.logoutTimer = null;
        this.checkTimer = null;

        this.init();
    }

    init() {
        // Track user activity
        this.trackActivity();

        // Start checking for inactivity
        this.startChecking();

        // Create warning modal
        this.createWarningModal();
    }

    trackActivity() {
        const events = ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart', 'click'];

        events.forEach(event => {
            document.addEventListener(event, () => {
                this.resetTimer();
            }, true);
        });
    }

    resetTimer() {
        this.lastActivity = Date.now();

        if (this.warningShown) {
            this.hideWarning();
        }
    }

    startChecking() {
        this.checkTimer = setInterval(() => {
            const inactiveTime = Date.now() - this.lastActivity;

            if (inactiveTime >= this.warningTime && !this.warningShown) {
                this.showWarning();
            }
        }, this.checkInterval);
    }

    createWarningModal() {
        const modal = document.createElement('div');
        modal.id = 'session-timeout-modal';
        modal.className = 'fixed inset-0 z-[9999] hidden';
        modal.innerHTML = `
            <!-- Backdrop -->
            <div class="fixed inset-0 bg-black bg-opacity-50 transition-opacity"></div>
            
            <!-- Modal -->
            <div class="fixed inset-0 flex items-center justify-center p-4">
                <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl max-w-md w-full transform transition-all">
                    <!-- Header -->
                    <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
                        <div class="flex items-center space-x-3">
                            <div class="flex-shrink-0">
                                <svg class="w-8 h-8 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">Session Timeout Warning</h3>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Body -->
                    <div class="px-6 py-5">
                        <p class="text-gray-700 dark:text-gray-300 mb-4">
                            Your session is about to expire due to inactivity.
                        </p>
                        <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4">
                            <p class="text-sm text-yellow-800 dark:text-yellow-200 mb-2">
                                You will be automatically logged out in:
                            </p>
                            <div class="flex items-center justify-center">
                                <div class="text-4xl font-bold text-yellow-600 dark:text-yellow-400" id="countdown-timer">
                                    30
                                </div>
                                <span class="ml-2 text-lg text-yellow-600 dark:text-yellow-400">seconds</span>
                            </div>
                        </div>
                        <p class="text-sm text-gray-600 dark:text-gray-400 mt-4">
                            Click "Stay Logged In" to continue your session.
                        </p>
                    </div>
                    
                    <!-- Footer -->
                    <div class="px-6 py-4 bg-gray-50 dark:bg-gray-900 rounded-b-xl flex justify-end space-x-3">
                        <button id="logout-now-btn" 
                                class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                            Logout Now
                        </button>
                        <button id="stay-logged-in-btn" 
                                class="px-6 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 transition-colors">
                            Stay Logged In
                        </button>
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(modal);

        // Attach event listeners
        document.getElementById('stay-logged-in-btn').addEventListener('click', () => {
            this.resetTimer();
        });

        document.getElementById('logout-now-btn').addEventListener('click', () => {
            this.logout();
        });
    }

    showWarning() {
        this.warningShown = true;
        const modal = document.getElementById('session-timeout-modal');
        modal.classList.remove('hidden');

        // Start countdown
        let timeLeft = this.logoutTime / 1000; // Convert to seconds
        const countdownEl = document.getElementById('countdown-timer');

        const updateCountdown = () => {
            countdownEl.textContent = Math.ceil(timeLeft);
            timeLeft -= 1;

            if (timeLeft < 0) {
                this.logout();
            }
        };

        updateCountdown();
        this.logoutTimer = setInterval(updateCountdown, 1000);
    }

    hideWarning() {
        this.warningShown = false;
        const modal = document.getElementById('session-timeout-modal');
        modal.classList.add('hidden');

        if (this.logoutTimer) {
            clearInterval(this.logoutTimer);
            this.logoutTimer = null;
        }
    }

    logout() {
        // Clear all timers
        if (this.checkTimer) {
            clearInterval(this.checkTimer);
        }
        if (this.logoutTimer) {
            clearInterval(this.logoutTimer);
        }

        // Redirect to logout
        window.location.href = '/auth/logout.php';
    }

    destroy() {
        if (this.checkTimer) {
            clearInterval(this.checkTimer);
        }
        if (this.logoutTimer) {
            clearInterval(this.logoutTimer);
        }

        const modal = document.getElementById('session-timeout-modal');
        if (modal) {
            modal.remove();
        }
    }
}

// Auto-initialize when DOM is ready
document.addEventListener('DOMContentLoaded', function () {
    // Only initialize for logged-in users (check if session exists)
    if (document.body.dataset.userLoggedIn === 'true') {
        window.sessionTimeoutManager = new SessionTimeoutManager({
            warningTime: 2 * 60 * 1000,  // 2 minutes
            logoutTime: 30 * 1000         // 30 seconds
        });
    }
});
