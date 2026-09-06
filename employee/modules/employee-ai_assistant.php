<?php
/**
 * AI HR Assistant - Employee Page
 * Chat interface for natural language HR queries
 */
require_once __DIR__ . '/../../config/config.php';
requireAuth();
checkRole(['employee', 'supervisor', 'unit_head', 'hr_admin', 'finance', 'admin', 'super admin']);

$page_title = 'AI HR Assistant';
$employeeId = getCurrentEmployeeId($db) ?? $_SESSION['employee_id'] ?? $_SESSION['user_id'] ?? null;
$userName = $_SESSION['full_name'] ?? $_SESSION['fname'] ?? 'there';
$baseUrl = BASE_URL;

include __DIR__ . '/../../includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-semibold text-gray-900 dark:text-white flex items-center gap-3">
        <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl bg-gradient-to-br from-teal-500 to-emerald-600 text-white shadow-lg">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg>
        </span>
        AI HR Assistant
    </h1>
    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">Ask me anything about your attendance, leave, claims, schedule, or HR policies</p>
</div>

<div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
    <!-- Chat Area (Main) -->
    <div class="lg:col-span-3">
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 flex flex-col" style="height: 70vh; min-height: 500px;">
            
            <!-- Chat Header -->
            <div class="px-5 py-3 border-b border-gray-200 dark:border-gray-700 flex items-center gap-3">
                <div class="relative">
                    <div class="w-9 h-9 rounded-full bg-gradient-to-br from-teal-500 to-emerald-600 flex items-center justify-center text-white">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg>
                    </div>
                    <div class="absolute -bottom-0.5 -right-0.5 w-3 h-3 bg-green-500 rounded-full border-2 border-white dark:border-gray-800"></div>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-gray-900 dark:text-white">HR AI Assistant</h3>
                    <p class="text-xs text-green-600 dark:text-green-400">Online</p>
                </div>
                <div class="ml-auto">
                    <button onclick="clearChat()" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 p-1 rounded-md hover:bg-gray-100 dark:hover:bg-gray-700" title="Clear chat">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/></svg>
                    </button>
                </div>
            </div>

            <!-- Messages Container -->
            <div id="chatMessages" class="flex-1 overflow-y-auto px-5 py-4 space-y-4">
                <!-- Welcome Message -->
                <div class="flex items-start gap-3 ai-message" data-animate>
                    <div class="w-8 h-8 rounded-full bg-gradient-to-br from-teal-500 to-emerald-600 flex items-center justify-center text-white flex-shrink-0">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg>
                    </div>
                    <div class="max-w-[80%] bg-gray-100 dark:bg-gray-700 rounded-2xl rounded-tl-md px-4 py-3">
                        <p class="text-sm text-gray-800 dark:text-gray-200">
                            Hello, <strong><?php echo htmlspecialchars($userName); ?></strong>! I'm your AI HR Assistant. I can help you with:
                        </p>
                        <ul class="text-sm text-gray-700 dark:text-gray-300 mt-2 space-y-1 list-disc list-inside">
                            <li>Attendance records & status</li>
                            <li>Leave balances & requests</li>
                            <li>Claims & reimbursements</li>
                            <li>Schedules & shifts</li>
                            <li>Timesheets & hours worked</li>
                            <li>HR policies & regulations</li>
                        </ul>
                        <p class="text-sm text-gray-700 dark:text-gray-300 mt-2">How can I help you today?</p>
                    </div>
                </div>
            </div>

            <!-- Input Area -->
            <div class="px-5 py-3 border-t border-gray-200 dark:border-gray-700">
                <form id="chatForm" class="flex gap-3">
                    <div class="flex-1 relative">
                        <input 
                            type="text" 
                            id="chatInput" 
                            placeholder="Ask me anything about your HR records..." 
                            class="w-full px-4 py-2.5 pr-10 rounded-xl border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-700 text-gray-900 dark:text-white placeholder-gray-400 focus:ring-2 focus:ring-teal-500 focus:border-transparent text-sm"
                            autocomplete="off"
                            maxlength="2000"
                        />
                        <span id="charCount" class="absolute right-3 top-1/2 -translate-y-1/2 text-xs text-gray-400 hidden">0/2000</span>
                    </div>
                    <button type="submit" id="sendBtn" class="inline-flex items-center justify-center px-4 py-2.5 rounded-xl bg-gradient-to-r from-teal-600 to-emerald-600 text-white font-medium text-sm hover:from-teal-700 hover:to-emerald-700 focus:ring-2 focus:ring-teal-500 focus:ring-offset-2 transition-all shadow-sm disabled:opacity-50 disabled:cursor-not-allowed">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-1.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                        Send
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Quick Actions Sidebar -->
    <div class="space-y-4">
        <!-- Quick Questions -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-3 flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-teal-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                Quick Questions
            </h3>
            <div class="space-y-2">
                <button onclick="quickAsk('What is my attendance status today?')" class="w-full text-left px-3 py-2 rounded-lg text-xs text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-700/50 hover:bg-teal-50 dark:hover:bg-teal-900/20 hover:text-teal-700 dark:hover:text-teal-400 transition-colors">
                    My attendance today?
                </button>
                <button onclick="quickAsk('What is my leave balance?')" class="w-full text-left px-3 py-2 rounded-lg text-xs text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-700/50 hover:bg-teal-50 dark:hover:bg-teal-900/20 hover:text-teal-700 dark:hover:text-teal-400 transition-colors">
                    My leave balance?
                </button>
                <button onclick="quickAsk('What is the status of my claims?')" class="w-full text-left px-3 py-2 rounded-lg text-xs text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-700/50 hover:bg-teal-50 dark:hover:bg-teal-900/20 hover:text-teal-700 dark:hover:text-teal-400 transition-colors">
                    My claims status?
                </button>
                <button onclick="quickAsk('What shift am I assigned to today?')" class="w-full text-left px-3 py-2 rounded-lg text-xs text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-700/50 hover:bg-teal-50 dark:hover:bg-teal-900/20 hover:text-teal-700 dark:hover:text-teal-400 transition-colors">
                    My shift today?
                </button>
                <button onclick="quickAsk('How many hours did I work this period?')" class="w-full text-left px-3 py-2 rounded-lg text-xs text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-700/50 hover:bg-teal-50 dark:hover:bg-teal-900/20 hover:text-teal-700 dark:hover:text-teal-400 transition-colors">
                    My hours this period?
                </button>
                <button onclick="quickAsk('Show my attendance summary this month')" class="w-full text-left px-3 py-2 rounded-lg text-xs text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-700/50 hover:bg-teal-50 dark:hover:bg-teal-900/20 hover:text-teal-700 dark:hover:text-teal-400 transition-colors">
                    Monthly attendance summary?
                </button>
            </div>
        </div>

        <!-- Policy Quick Ref -->
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm border border-gray-200 dark:border-gray-700 p-4">
            <h3 class="text-sm font-semibold text-gray-900 dark:text-white mb-3 flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-blue-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                Policy Quick Ask
            </h3>
            <div class="space-y-2">
                <button onclick="quickAsk('What is the overtime premium rate?')" class="w-full text-left px-3 py-2 rounded-lg text-xs text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-700/50 hover:bg-blue-50 dark:hover:bg-blue-900/20 hover:text-blue-700 dark:hover:text-blue-400 transition-colors">
                    OT premium rate?
                </button>
                <button onclick="quickAsk('What are the PH leave entitlements?')" class="w-full text-left px-3 py-2 rounded-lg text-xs text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-700/50 hover:bg-blue-50 dark:hover:bg-blue-900/20 hover:text-blue-700 dark:hover:text-blue-400 transition-colors">
                    PH leave entitlements?
                </button>
                <button onclick="quickAsk('What are the claim categories and limits?')" class="w-full text-left px-3 py-2 rounded-lg text-xs text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-700/50 hover:bg-blue-50 dark:hover:bg-blue-900/20 hover:text-blue-700 dark:hover:text-blue-400 transition-colors">
                    Claim categories & limits?
                </button>
                <button onclick="quickAsk('What is the night differential rate?')" class="w-full text-left px-3 py-2 rounded-lg text-xs text-gray-700 dark:text-gray-300 bg-gray-50 dark:bg-gray-700/50 hover:bg-blue-50 dark:hover:bg-blue-900/20 hover:text-blue-700 dark:hover:text-blue-400 transition-colors">
                    Night differential rate?
                </button>
            </div>
        </div>

        <!-- AI Info -->
        <div class="bg-gradient-to-br from-teal-50 to-emerald-50 dark:from-teal-900/20 dark:to-emerald-900/20 rounded-xl border border-teal-200 dark:border-teal-800 p-4">
            <div class="flex items-start gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-teal-600 dark:text-teal-400 mt-0.5 flex-shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/></svg>
                <div class="text-xs text-teal-800 dark:text-teal-300">
                    <p class="font-semibold mb-1">About this AI</p>
                    <p>This assistant uses your actual HR data to provide personalized responses. Your queries are logged for audit compliance.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
const BASE_URL = '<?php echo $baseUrl; ?>';
const chatMessages = document.getElementById('chatMessages');
const chatForm = document.getElementById('chatForm');
const chatInput = document.getElementById('chatInput');
const sendBtn = document.getElementById('sendBtn');
const charCount = document.getElementById('charCount');

let conversationHistory = [];
let isProcessing = false;

// Character counter
chatInput.addEventListener('input', () => {
    const len = chatInput.value.length;
    if (len > 1800) {
        charCount.classList.remove('hidden');
        charCount.textContent = `${len}/2000`;
        charCount.classList.toggle('text-red-500', len > 1950);
    } else {
        charCount.classList.add('hidden');
    }
});

// Form submit
chatForm.addEventListener('submit', (e) => {
    e.preventDefault();
    const msg = chatInput.value.trim();
    if (!msg || isProcessing) return;
    sendMessage(msg);
});

function quickAsk(question) {
    if (isProcessing) return;
    chatInput.value = question;
    sendMessage(question);
}

async function sendMessage(message) {
    isProcessing = true;
    sendBtn.disabled = true;
    chatInput.value = '';
    charCount.classList.add('hidden');

    // Add user message bubble
    appendMessage('user', message);
    conversationHistory.push({ role: 'user', content: message });

    // Add typing indicator
    const typingId = showTypingIndicator();

    try {
        const response = await fetch(`${BASE_URL}/api/ai/chat.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ message, history: conversationHistory.slice(-10) })
        });

        const data = await response.json();
        removeTypingIndicator(typingId);

        if (data.success) {
            appendMessage('ai', data.message, data.source);
            conversationHistory.push({ role: 'assistant', content: data.message });
        } else {
            appendMessage('ai', data.message || 'Sorry, something went wrong. Please try again.', 'error');
        }
    } catch (err) {
        removeTypingIndicator(typingId);
        appendMessage('ai', 'Network error. Please check your connection and try again.', 'error');
    }

    isProcessing = false;
    sendBtn.disabled = false;
    chatInput.focus();
}

function appendMessage(role, content, source = '') {
    const div = document.createElement('div');
    div.className = `flex items-start gap-3 ${role === 'user' ? 'justify-end' : ''} animate-fade-in`;
    
    const formatted = formatMarkdown(content);

    if (role === 'user') {
        div.innerHTML = `
            <div class="max-w-[80%] bg-teal-600 text-white rounded-2xl rounded-tr-md px-4 py-3">
                <p class="text-sm">${escapeHtml(content)}</p>
            </div>
            <div class="w-8 h-8 rounded-full bg-gray-300 dark:bg-gray-600 flex items-center justify-center text-gray-700 dark:text-gray-300 flex-shrink-0">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
            </div>
        `;
    } else {
        const sourceTag = source === 'ai' ? '<span class="text-[10px] text-teal-600 dark:text-teal-400 font-medium">AI-Powered</span>' : 
                          source === 'error' ? '<span class="text-[10px] text-red-500 font-medium">Error</span>' : 
                          '<span class="text-[10px] text-gray-400 font-medium">Smart Assistant</span>';
        
        div.innerHTML = `
            <div class="w-8 h-8 rounded-full bg-gradient-to-br from-teal-500 to-emerald-600 flex items-center justify-center text-white flex-shrink-0">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg>
            </div>
            <div class="max-w-[80%]">
                <div class="bg-gray-100 dark:bg-gray-700 rounded-2xl rounded-tl-md px-4 py-3">
                    <div class="text-sm text-gray-800 dark:text-gray-200 ai-response-content">${formatted}</div>
                </div>
                <div class="mt-1 pl-1">${sourceTag}</div>
            </div>
        `;
    }

    chatMessages.appendChild(div);
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

function showTypingIndicator() {
    const id = 'typing-' + Date.now();
    const div = document.createElement('div');
    div.id = id;
    div.className = 'flex items-start gap-3 animate-fade-in';
    div.innerHTML = `
        <div class="w-8 h-8 rounded-full bg-gradient-to-br from-teal-500 to-emerald-600 flex items-center justify-center text-white flex-shrink-0">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 8V4H8"/><rect width="16" height="12" x="4" y="8" rx="2"/><path d="M2 14h2"/><path d="M20 14h2"/><path d="M15 13v2"/><path d="M9 13v2"/></svg>
        </div>
        <div class="bg-gray-100 dark:bg-gray-700 rounded-2xl rounded-tl-md px-4 py-3">
            <div class="flex space-x-1.5">
                <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 0ms"></div>
                <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 150ms"></div>
                <div class="w-2 h-2 bg-gray-400 rounded-full animate-bounce" style="animation-delay: 300ms"></div>
            </div>
        </div>
    `;
    chatMessages.appendChild(div);
    chatMessages.scrollTop = chatMessages.scrollHeight;
    return id;
}

function removeTypingIndicator(id) {
    const el = document.getElementById(id);
    if (el) el.remove();
}

function clearChat() {
    conversationHistory = [];
    const welcome = chatMessages.querySelector('.ai-message');
    chatMessages.innerHTML = '';
    if (welcome) chatMessages.appendChild(welcome);
}

function escapeHtml(text) {
    const d = document.createElement('div');
    d.textContent = text;
    return d.innerHTML;
}

function formatMarkdown(text) {
    // Basic markdown → HTML
    let html = escapeHtml(text);
    
    // Bold: **text**
    html = html.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
    // Italic: *text*
    html = html.replace(/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/g, '<em>$1</em>');
    
    // Headers: ### text
    html = html.replace(/^### (.+)$/gm, '<h4 class="font-semibold text-gray-900 dark:text-white mt-2 mb-1">$1</h4>');
    html = html.replace(/^## (.+)$/gm, '<h3 class="font-semibold text-gray-900 dark:text-white mt-2 mb-1">$1</h3>');
    
    // Table support
    if (html.includes('|') && html.includes('---')) {
        html = html.replace(/(\|.+\|\n\|[-|\s]+\|\n(?:\|.+\|\n?)+)/g, (match) => {
            const lines = match.trim().split('\n');
            if (lines.length < 3) return match;
            const headers = lines[0].split('|').filter(c => c.trim());
            const rows = lines.slice(2).map(l => l.split('|').filter(c => c.trim()));
            let table = '<table class="text-xs w-full mt-2 mb-2 border-collapse"><thead><tr>';
            headers.forEach(h => { table += `<th class="border border-gray-300 dark:border-gray-600 px-2 py-1 bg-gray-50 dark:bg-gray-600 font-semibold">${h.trim()}</th>`; });
            table += '</tr></thead><tbody>';
            rows.forEach(r => {
                table += '<tr>';
                r.forEach(c => { table += `<td class="border border-gray-300 dark:border-gray-600 px-2 py-1">${c.trim()}</td>`; });
                table += '</tr>';
            });
            table += '</tbody></table>';
            return table;
        });
    }
    
    // Unordered list: - text
    html = html.replace(/^- (.+)$/gm, '<li class="ml-4 list-disc">$1</li>');
    html = html.replace(/(<li[^>]*>.*<\/li>\n?)+/gs, '<ul class="space-y-0.5 my-1">$&</ul>');
    
    // Numbered list: 1. text
    html = html.replace(/^\d+\. (.+)$/gm, '<li class="ml-4 list-decimal">$1</li>');
    
    // Line breaks
    html = html.replace(/\n\n/g, '<br><br>');
    html = html.replace(/\n/g, '<br>');
    
    return html;
}

// Auto-focus input
chatInput.focus();

// Add animation style
const style = document.createElement('style');
style.textContent = `
    @keyframes fadeIn { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: translateY(0); } }
    .animate-fade-in { animation: fadeIn 0.3s ease-out forwards; }
    .ai-response-content ul { list-style-type: disc; padding-left: 1rem; }
    .ai-response-content ol { list-style-type: decimal; padding-left: 1rem; }
    .ai-response-content table { font-size: 0.75rem; }
`;
document.head.appendChild(style);
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
