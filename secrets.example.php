<?php
/* Образец secrets.php. Обычно его пишет установщик (install.php или php bin/server.php install) —
   руками не нужно. Ключи ИИ — свои; пусто — без этого сервиса. Потом всё меняется в админке → «Настройки». */

declare(strict_types=1);

return [
    'pass' => '',              // пароль базы MySQL; для SQLite не нужен
    'admin_pass_hash' => '',   // php -r 'echo password_hash("пароль", PASSWORD_DEFAULT);'
    'ai_key_deepseek' => '',   // DeepSeek — https://platform.deepseek.com
    'ai_key_openrouter' => '', // OpenRouter — https://openrouter.ai
    'voice_api_key' => '',     // OpenAI — голос (иначе голос браузера)
    'jev_api_key' => '',       // Jev (TypeSafe) — сигнальщик прогона
    'ftp_pass' => '',          // публикация на свой хостинг — по желанию
];
