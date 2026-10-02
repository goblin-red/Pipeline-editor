# Goblin — flowchart canvas for AI agents

**beta v0.95** · [Русский ниже](#гоблин--холст-блок-схем-для-ии-агентов)

Draw a process as a flowchart — AI agents (Claude Code, Codex and others) walk through it step by step:
the server hands out tasks, checks results and keeps the run on track. Built-in AI assistant, a catalog of
ready-made schemes, voice input, English and Russian interface.

## Requirements

- PHP 8.1+ with `pdo_sqlite` or `pdo_mysql`, `curl`, `mbstring`, `zip`
- Database: **SQLite** (one file, nothing to set up) or **MySQL / MariaDB**

## Install on your computer (macOS)

```bash
brew install php                 # macOS has no PHP of its own
git clone https://github.com/goblin-red/workflow.git goblin
cd goblin
php bin/server.php install       # a few questions: database, admin password, AI keys (optional)
php bin/server.php serve         # → http://localhost:8080
```

With XAMPP or MAMP: put the `goblin` folder into `htdocs` and open `http://localhost/goblin/` — the installer starts by itself.

## Install on a website (hosting)

1. Upload the files into the `goblin` folder of your site (or the site root).
2. Open `https://your-site/goblin/` — the installer page opens.
3. Choose the database (MySQL is recommended for a website), set the admin password, optionally your AI keys.

After installation the installer is closed. Settings, AI keys and people — in `admin.php`.
AI keys are yours: DeepSeek, OpenRouter, OpenAI (voice), Jev — none are included.

---

# Гоблин — холст блок-схем для ИИ-агентов

Рисуете процесс блок-схемой — ИИ-агенты (Claude Code, Codex и другие) проходят его шаг за шагом:
сервер выдаёт задания, проверяет результаты и ведёт прогон. Встроенный ИИ-помощник, каталог готовых схем,
голосовой ввод, русский и английский интерфейс.

## Что нужно

- PHP 8.1+ с `pdo_sqlite` или `pdo_mysql`, `curl`, `mbstring`, `zip`
- База: **SQLite** (один файл, настраивать нечего) или **MySQL / MariaDB**

## Установка на свой компьютер (macOS)

```bash
brew install php                 # своего PHP в macOS нет
git clone https://github.com/goblin-red/workflow.git goblin
cd goblin
php bin/server.php install       # несколько вопросов: база, пароль админки, ключи ИИ (по желанию)
php bin/server.php serve         # → http://localhost:8080
```

С XAMPP или MAMP: папку `goblin` — в `htdocs`, открыть `http://localhost/goblin/` — установщик откроется сам.

## Установка на сайт (хостинг)

1. Залить файлы в папку `goblin` сайта (или в корень).
2. Открыть `https://ваш-сайт/goblin/` — откроется страница установки.
3. Выбрать базу (для сайта лучше MySQL), задать пароль админки, по желанию — свои ключи ИИ.

После установки установщик закрыт. Настройки, ключи ИИ и люди — в `admin.php`.
Ключи ИИ — свои: DeepSeek, OpenRouter, OpenAI (голос), Jev — в комплекте их нет.
