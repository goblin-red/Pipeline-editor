<?php
/* Sample of secrets.php. Normally the installer writes it (install.php or php bin/server.php install) —
   no need to edit by hand. AI keys are your own; empty means the service is off. Change later in admin → Settings. */

declare(strict_types=1);

return [
    'pass' => '',              // MySQL database password; not needed for SQLite
    'admin_pass_hash' => '',   // php -r 'echo password_hash("password", PASSWORD_DEFAULT);'
    'ai_key_deepseek' => '',   // DeepSeek — https://platform.deepseek.com
    'ai_key_openrouter' => '', // OpenRouter — https://openrouter.ai
    'voice_api_key' => '',     // OpenAI — voice (otherwise the browser voice)
    'jev_api_key' => '',       // Jev (TypeSafe) — signal model for decisions
    'ftp_pass' => '',          // publishing to your hosting — optional
];
