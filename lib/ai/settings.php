<?php
/* Правила для встроенного ИИ: разделы инструкций по маркерам.
   Отдаёт: aiRules(), aiSection(), aiLimit(), aiSpent().
   Не делает: не зовёт модель.

   Маркер в файле: <!-- rule:assistant -->. Общие навыки и режимы — deepseek.md, рисование —
   рисование.md (rule:drawing … rule:end), конструктор — конструктор.md (rule:constructor … rule:end)
   плюс «Опыт каталога» из паспортов (catalogDigest, lib/templates/catalog.php).
   Файлы лежат по языкам: instructions/ru/, instructions/en/ — берётся язык проекта (langAgents). */

declare(strict_types=1);

const AI_DAILY_LIMIT = 100;

function aiRules(string $section): string
{
    $dir = config()['instructions_dir'];
    $file = static fn(string $name) => langFile($dir, $name, langAgents());
    $out = aiSection($file('deepseek.md'), 'common');
    // Конструктору — его метод (конструктор.md) и опыт каталога из паспортов; прочим — раздел режима.
    $out .= "\n\n" . ($section === 'constructor'
        ? aiSection($file('конструктор.md'), 'constructor') . "\n\n" . catalogDigest()
        : aiSection($file('deepseek.md'), $section));
    if (in_array($section, ['constructor', 'assistant'], true)) {
        $out .= "\n\n" . aiSection($file('рисование.md'), 'drawing');
    }
    return trim($out);
}

/** Кусок файла от маркера до следующего маркера или конца. */
function aiSection(string $file, string $name): string
{
    if (!is_file($file)) return '';
    $text = (string) file_get_contents($file);
    $start = strpos($text, '<!-- rule:' . $name . ' -->');
    if ($start === false) return '';
    $start += strlen('<!-- rule:' . $name . ' -->');
    $end = strpos($text, '<!-- rule:', $start);
    return trim($end === false ? substr($text, $start) : substr($text, $start, $end - $start));
}

/** Сколько обращений к модели уже сделано за сутки этим проектом. */
function aiSpent(int $projectId): int
{
    return (int) dbValue(
        'SELECT COUNT(*) FROM ai_jobs j JOIN ai_chats c ON c.id = j.chat_id
          WHERE c.project_id = ? AND j.created_at > NOW(3) - INTERVAL 1 DAY',
        [$projectId]
    );
}

function aiLimit(int $projectId): void
{
    if (aiSpent($projectId) >= AI_DAILY_LIMIT) {
        throw new ApiError(t('agents.ai.daily_limit'), 'conflict');
    }
}
