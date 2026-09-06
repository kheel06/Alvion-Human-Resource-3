<?php
/**
 * AI Configuration for HR3 System
 * Supports OpenAI-compatible APIs (OpenAI, Azure OpenAI, local LLMs via LM Studio/Ollama)
 * Falls back to built-in rule-based engine when no API key is configured
 */

// AI Provider Settings
define('AI_ENABLED', true);
define('AI_API_KEY', getenv('AI_API_KEY') ?: '');  // Set via environment variable
define('AI_API_URL', getenv('AI_API_URL') ?: 'https://api.openai.com/v1/chat/completions');
define('AI_MODEL', getenv('AI_MODEL') ?: 'gpt-3.5-turbo');
define('AI_MAX_TOKENS', (int)(getenv('AI_MAX_TOKENS') ?: 1024));
define('AI_TEMPERATURE', (float)(getenv('AI_TEMPERATURE') ?: 0.3));

// Whether to use LLM (requires valid API key) or rule-based fallback
define('AI_USE_LLM', !empty(AI_API_KEY));

// System prompt for the HR AI assistant
define('AI_SYSTEM_PROMPT', <<<'PROMPT'
You are an intelligent HR Assistant for a Philippine hospital management system called Alvion. You help employees and HR administrators with:

1. **Attendance & Time Tracking** - QR-based attendance, daily attendance records, exceptions, overtime
2. **Shift & Scheduling** - Shift templates, rosters, swap requests, schedule queries
3. **Timesheet Management** - Pay period timesheets, hours worked, submission and approval
4. **Leave Management** - Leave balances, requests, PH labor law policies (VL, SL, ML, PL, EL, SPL)
5. **Claims & Reimbursement** - Expense claims, categories, approval status, payment tracking

Key policies:
- Standard work hours: 8 hours/day
- Overtime premium: 1.25x regular rate
- Night differential: 10% additional (10PM-6AM)
- Rest day premium: 1.30x
- Timezone: Asia/Manila (Philippine Standard Time)

Always be helpful, concise, and accurate. Reference Philippine labor laws when relevant.
When you receive employee data context, use it to provide personalized responses.
Format responses in a clear, readable way. Use bullet points for lists.
PROMPT
);
