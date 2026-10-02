<?php
/* Проверки сдачи: одно место на все правила против залпов и вранья.
   Отдаёт: checkSubmit(), filesReview(), looksLikeText(), mimeFitsExt(), MIME_BY_EXT, assetFromFile(), assetAtAgent(),
           fileKindOf().
   Не делает: не меняет состояние шага — только проверяет и возвращает замечания.

   Часть правил жёсткая всегда, часть переключается галочкой проекта
   «строгие проверки результата» (projects.strict_checks). Причина простая:
   там, где делают картинки, размер файла — доказательство работы,
   а там, где пишут текст, эти проверки только мешают. */

declare(strict_types=1);

/**
 * Проверить сдачу. Возвращает список замечаний (они не отменяют запись).
 * Нарушение жёсткого правила бросает ApiError.
 */
function checkSubmit(array $project, array $run, array $step, string $result, array $files): array
{
    $strict = (int) $project['strict_checks'] === 1;
    $warnings = [];

    // ── Результат ────────────────────────────────────────────
    if ($result === '' && !$files) {
        throw new ApiError(ta('agents.checks.nothing_to_submit'), 'conflict', ['code2' => 'no_result']);
    }
    if ($result !== '') {
        if (mb_strlen($result) > 200)      throw new ApiError(ta('agents.checks.result_200'));
        if (str_contains($result, "\n"))   throw new ApiError(ta('agents.checks.result_no_breaks'));
        if ($result !== '' && ($result[0] === '{' || $result[0] === '[')) {
            throw new ApiError(ta('agents.checks.result_not_json'));
        }
    }

    // ── Образец ответа ───────────────────────────────────────
    // У блока может быть написано, как выглядит правильный ответ:
    // «a=<число> s=<число>». Не сошлось — работа не сделана как просили.
    $answer = $step['element_id'] ? trim((string) runProp((int) $step['element_id'], 'answer', '')) : '';
    if ($answer !== '' && $result !== '') {
        $fits = answerFits($result, $answer);

        // Образец может быть написан так, что регулярка из него не собирается.
        // Это вина схемы, а не worker, — и говорить надо именно так.
        if ($fits === null) {
            throw new ApiError(ta('agents.checks.answer_bad_sample', ['answer' => $answer]),
                'conflict', ['code2' => 'bad_pattern']);
        }
        if ($fits === false) {
            $say = ta('agents.checks.answer_not_sample', ['answer' => $answer, 'result' => $result, 'sample' => answerSample($answer)]);
            if ($strict) throw new ApiError($say, 'conflict', ['code2' => 'bad_answer']);
            $warnings[] = $say;
        }
    }

    // ── Обязательные выходы ──────────────────────────────────
    $outputs = $step['element_id'] ? (array) runProp((int) $step['element_id'], 'outputs', []) : [];
    if ($outputs) {
        $given = array_filter(array_map(static fn($f) => (string) ($f['output'] ?? ''), $files));
        $missing = array_diff($outputs, $given);
        if ($missing) {
            throw new ApiError(ta('agents.checks.outputs_missing', ['list' => implode(', ', $missing)]), 'conflict', ['code2' => 'no_result']);
        }
    }

    // ── Файлы ────────────────────────────────────────────────
    $workDir = realpath((string) $run['work_dir']) ?: '';
    foreach ($files as $file) {
        $path = (string) ($file['path'] ?? $file);
        $real = realpath($path);
        if (!$real || !is_file($real)) {
            throw new ApiError(ta('agents.checks.file_missing', ['path' => $path]), 'conflict', ['code2' => 'no_file']);
        }
        if ($workDir === '' || !str_starts_with($real, $workDir . DIRECTORY_SEPARATOR)) {
            throw new ApiError(ta('agents.checks.file_outside', ['path' => $path]), 'conflict', ['code2' => 'outside_workdir']);
        }
        if (filesize($real) === 0) {
            throw new ApiError(ta('agents.checks.file_empty', ['path' => $path]), 'conflict', ['code2' => 'empty_file']);
        }

        /* Файл старше выдачи шага — значит, сделан заранее, «впрок».
           Но правило это про работу, а не про исходники: то, что worker сделал,
           лежит в `out/`, а всё, что рядом с ним в рабочей папке, — вход,
           положенный до прогона. Вход старым быть обязан. */
        $madeHere = str_starts_with($real, $workDir . DIRECTORY_SEPARATOR . 'out' . DIRECTORY_SEPARATOR);
        $opened = (int) dbValue('SELECT UNIX_TIMESTAMP(opened_at) FROM run_steps WHERE id = ?', [$step['id']]);
        if ($madeHere && filemtime($real) < $opened - 2) {
            $say = ta('agents.checks.file_old', ['name' => basename($real)]);
            if ($strict) throw new ApiError($say, 'conflict', ['code2' => 'file_before_step']);
            $warnings[] = $say;
        }

        // Расширение не совпадает с содержимым.
        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $mime = @mime_content_type($real) ?: '';
        // На коротких файлах угадывание типа врёт: «0\n» в .txt даёт
        // application/octet-stream. Смотрим сами, текст ли это.
        if (in_array($ext, ['txt', 'md', 'json', 'csv'], true) && looksLikeText($real)) $mime = 'text/plain';
        if ($ext !== '' && $mime !== '' && !mimeFitsExt($mime, $ext)) {
            $say = ta('agents.checks.ext_mismatch', ['ext' => $ext, 'mime' => $mime, 'name' => basename($real)]);
            $warnings[] = $say;   // мягко всегда: угадывание типа врёт чаще, чем ловит
        }

        // Размер в тексте результата должен совпасть с настоящим.
        if ($result !== '' && preg_match('/(\d{2,5})\s*[x×]\s*(\d{2,5})/u', $result, $m)) {
            $size = @getimagesize($real);
            if ($size && ((int) $m[1] !== $size[0] || (int) $m[2] !== $size[1])) {
                $say = ta('agents.checks.size_mismatch', ['a' => (int) $m[1], 'b' => (int) $m[2], 'c' => $size[0], 'd' => $size[1]]);
                if ($strict) throw new ApiError($say, 'conflict', ['code2' => 'size_mismatch']);
                $warnings[] = $say;
            }
        }

        // Результат совпадает с входом байт в байт — работа не сделана.
        $sha = hash_file('sha256', $real);
        $sameInput = dbValue(
            'SELECT a.id FROM asset_links l JOIN assets a ON a.id = l.asset_id
              WHERE l.element_id = ? AND l.role IN (\'input\',\'reference\') AND a.sha256 = ?',
            [$step['element_id'], $sha]
        );
        if ($sameInput) {
            $say = ta('agents.checks.same_as_input', ['name' => basename($real)]);
            if ($strict) throw new ApiError($say, 'conflict', ['code2' => 'same_as_input']);
            $warnings[] = $say;
        }
    }

    // ── Внешние заявки ───────────────────────────────────────
    $open = (int) dbValue("SELECT COUNT(*) FROM run_jobs WHERE step_id = ? AND state = 'sent' AND job_id = ''", [$step['id']]);
    if ($open > 0) {
        throw new ApiError(ta('agents.checks.gap_job_open'), 'conflict', ['code2' => 'job_open']);
    }

    return $warnings;
}

/** Текст ли это на самом деле: печатные знаки, без нулевых байтов. */
function looksLikeText(string $path): bool
{
    $head = (string) @file_get_contents($path, false, null, 0, 4096);
    if ($head === '') return false;
    if (str_contains($head, "\0")) return false;
    return preg_match('//u', $head) === 1;
}

/** Тип файла по расширению: сверка сдачи и файлы у агента в вебе (их байтов сервер не видит). */
const MIME_BY_EXT = [
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
    'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'pdf' => 'application/pdf',
    'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'mp3' => 'audio/mpeg', 'wav' => 'audio/x-wav',
    'json' => 'application/json', 'txt' => 'text/plain', 'md' => 'text/plain',
    'zip' => 'application/zip',
];

function mimeFitsExt(string $mime, string $ext): bool
{
    if (!isset(MIME_BY_EXT[$ext])) return true;
    return str_starts_with($mime, explode('/', MIME_BY_EXT[$ext])[0]);
}

/** Файл на диске становится ассетом проекта: путь, размер, отпечаток. */
function assetFromFile(int $projectId, string $path, int $rev): int
{
    $real = realpath($path);
    if (!$real) throw new ApiError(ta('agents.checks.file_missing', ['path' => $path]), 'not_found');

    $sha = hash_file('sha256', $real);
    $exists = dbValue('SELECT id FROM assets WHERE project_id = ? AND sha256 = ? AND uri = ?', [$projectId, $sha, $real]);
    if ($exists) return (int) $exists;

    dbRun(
        'INSERT INTO assets (project_id, kind, title, uri, mime, bytes, sha256, original_name, rev)
         VALUES (?,?,?,?,?,?,?,?,?)',
        [$projectId, fileKindOf($real), basename($real), $real, @mime_content_type($real) ?: '',
         filesize($real), $sha, basename($real), $rev]
    );
    return dbId();
}

/**
 * Файл у агента (Гоблин в вебе): байтов на сервере нет — путь от папки схемы, размер со слов агента,
 * тип по расширению (решение хозяина 30.09.2026). Относительный uri и отличает его от файла на диске сервера.
 */
function assetAtAgent(int $projectId, string $rel, ?int $bytes, int $rev): int
{
    $exists = dbValue('SELECT id FROM assets WHERE project_id = ? AND uri = ? AND file_key IS NULL', [$projectId, $rel]);
    if ($exists) {
        dbRun('UPDATE assets SET bytes = ?, rev = ? WHERE id = ?', [$bytes, $rev, (int) $exists]);
        return (int) $exists;
    }
    dbRun(
        'INSERT INTO assets (project_id, kind, title, uri, mime, bytes, original_name, rev) VALUES (?,?,?,?,?,?,?,?)',
        [$projectId, fileKindOf($rel), basename($rel), $rel,
         MIME_BY_EXT[strtolower(pathinfo($rel, PATHINFO_EXTENSION))] ?? '', $bytes, basename($rel), $rev]
    );
    return dbId();
}

/** Файл у агента: uri — путь от папки схемы (без «/» и «://»), байтов на сервере нет. */
function assetAtAgentUri(?string $fileKey, ?string $uri): bool
{
    $uri = (string) $uri;
    return $fileKey === null && $uri !== '' && $uri[0] !== '/' && !str_contains($uri, '://');
}

/** Чем открывать файл — по расширению. Список типов живёт в config.txt. */
function fileKindOf(string $path): string
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return match (true) {
        in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'bmp'], true) => 'image',
        in_array($ext, ['mp4', 'mov', 'webm', 'avi', 'mkv'], true)                => 'video',
        in_array($ext, ['mp3', 'wav', 'ogg', 'flac', 'm4a'], true)                => 'audio',
        $ext === 'pdf'                                                            => 'pdf',
        in_array($ext, ['doc', 'docx', 'rtf', 'odt'], true)                       => 'doc',
        in_array($ext, ['xls', 'xlsx', 'csv', 'tsv', 'ods'], true)                => 'table',
        in_array($ext, ['zip', 'rar', '7z', 'tar', 'gz'], true)                   => 'archive',
        in_array($ext, ['obj', 'fbx', 'glb', 'gltf', 'stl'], true)                => 'model',
        in_array($ext, ['md', 'txt', 'log'], true)                                => 'text',
        in_array($ext, ['py', 'js', 'php', 'sh', 'json', 'sql', 'html', 'css'], true) => 'code',
        default                                                                    => 'file',
    };
}

/**
 * Файлы сдачи глазами проверяющего — в момент приёмки, а не сдачи:
 * между ними файл могли стереть или подменить.
 * Отдаёт первую найденную проблему строкой или '' — всё на месте.
 *
 * Проверяется: файлы есть, не пустые, внутри рабочей папки; все обязательные
 * выходы сданы; нет заявок сервису без номера. Со строгими проверками —
 * ещё и «файл в out/ старше выдачи шага».
 */
function filesReview(array $project, array $run, array $step, array $graph): string
{
    $strict  = (int) ($project['strict_checks'] ?? 1) === 1;
    $workDir = realpath((string) $run['work_dir']) ?: '';
    $opened  = dbStamp($step['opened_at'] ?? null);   // время базы, не PHP

    $files = dbAll(
        "SELECT a.uri, l.output FROM asset_links l JOIN assets a ON a.id = l.asset_id
          WHERE l.step_id = ? AND l.role = 'result' ORDER BY l.id",
        [(int) $step['id']]
    );
    foreach ($files as $file) {
        $path = (string) $file['uri'];
        $real = $path !== '' ? realpath($path) : false;
        $name = basename($path);
        if (!$real || !is_file($real)) return ta('agents.checks.gap_file_missing', ['name' => $name]);
        if (filesize($real) === 0) return ta('agents.checks.gap_file_empty', ['name' => $name]);
        if ($workDir === '' || !str_starts_with($real, $workDir . DIRECTORY_SEPARATOR)) {
            return ta('agents.checks.gap_file_outside', ['name' => $name]);
        }
        $madeHere = str_starts_with($real, $workDir . DIRECTORY_SEPARATOR . 'out' . DIRECTORY_SEPARATOR);
        if ($strict && $madeHere && $opened && filemtime($real) < $opened - 2) {
            return ta('agents.checks.gap_file_old', ['name' => $name]);
        }
    }

    // Обязательные выходы: свойство outputs у блока.
    $outputs = graphProp($graph, (int) $step['element_id'], 'outputs', []);
    if (is_string($outputs)) $outputs = array_filter(array_map('trim', explode(',', $outputs)));
    $given = array_filter(array_map(static fn(array $f) => (string) $f['output'], $files));
    $missing = array_diff((array) $outputs, $given);
    if ($missing) return ta('agents.checks.gap_outputs', ['list' => implode(', ', $missing)]);

    $open = (int) dbValue("SELECT COUNT(*) FROM run_jobs WHERE step_id = ? AND state = 'sent' AND job_id = ''", [(int) $step['id']]);
    if ($open > 0) return ta('agents.checks.gap_job_short');

    return '';
}
