#!/bin/bash
# Сторож leader — страховка, а не рабочий круг.
#
# Что делает: смотрит op=where&short=1 прогона раз в 30 секунд и молча ждёт,
#   пока статус меняется. Завершается — и этим будит leader, который
#   запустил его в фоне, — когда пора вмешаться:
#     ТИШИНА        — статус не менялся 5 минут (worker молчит, или
#                     ромб/блок ждёт leader, а тот не заметил);
#     ПРОГОН ЗАКРЫТ — прогон пройден, остановлен или сорван (замечает
#                     за 30 секунд — снимать сторожа руками не нужно);
#     НЕТ СТАТУСА   — api.php два раза подряд не дал строку статуса
#                     (сервер лежит или такого прогона нет).
#   Проект на английском — те же строки по-английски: SILENCE, RUN CLOSED, NO STATUS.
# Чего не делает: ничего не пишет в прогон и сам никого не будит —
#   будит leader сам факт завершения фоновой команды.
#
# Запуск (в фоне):
#   bin/lead-watch.sh <ключ проекта> <rN> [секунд тишины, по умолчанию 300]
#   Порог тишины — целое число секунд больше нуля; другое значение — те же 300.
# Адрес сервера — GOBLIN_URL, по умолчанию http://localhost/goblin/api.php

KEY="$1"
RUN="$2"
QUIET="${3:-300}"
# Порог — целое больше нуля (10# — «010» не восьмеричное); иначе умолчание.
if [[ "$QUIET" =~ ^[0-9]{1,9}$ ]] && (( 10#$QUIET > 0 )); then QUIET=$((10#$QUIET)); else QUIET=300; fi
URL="${GOBLIN_URL:-http://localhost/goblin/api.php}"

if [ -z "$KEY" ] || [ -z "$RUN" ]; then
  echo "запуск: bin/lead-watch.sh <ключ проекта> <rN> [секунд тишины]"
  exit 2
fi

# Опрос — раз в 30 секунд (чаще, если порог тишины меньше: так гоняют проверки)
EVERY=30
[ "$QUIET" -lt "$EVERY" ] && EVERY="$QUIET"

# Одна строка статуса: «прогон r134 · running · принято шагов: 3 · сейчас: …»
status() {
  curl -s -m 20 "$URL?project=$KEY&op=where&run=$RUN&short=1" | head -1
}

last=""
since=$(date +%s)
misses=0
EN=""   # язык строк сторожа — как у статуса сервера: «прогон …» или «run …» (язык проекта)

while true; do
  now="$(status)"
  if [[ "$now" == run* ]]; then EN=1; elif [[ "$now" == прогон* ]]; then EN=0; fi

  # Сервер не ответил или ответил не строкой статуса
  if [[ "$now" != прогон* && "$now" != run* ]]; then
    misses=$((misses + 1))
    if [ "$misses" -ge 2 ]; then
      case "$EN" in
        1) echo "NO STATUS: $RUN — server answer: ${now:-empty}" ;;
        0) echo "НЕТ СТАТУСА: $RUN — ответ сервера: ${now:-пусто}" ;;
        *) echo "НЕТ СТАТУСА / NO STATUS: $RUN — ${now:-пусто / empty}" ;;
      esac
      exit 1
    fi
    sleep "$EVERY"
    continue
  fi
  misses=0

  # Прогон закрыт — страховка больше не нужна
  case "$now" in
    *" · done · "*|*" · stopped · "*|*" · failed · "*)
      if [ "$EN" = 1 ]; then echo "RUN CLOSED: $now"; else echo "ПРОГОН ЗАКРЫТ: $now"; fi
      exit 0
      ;;
  esac

  # Статус сменился — отсчёт тишины заново
  if [ "$now" != "$last" ]; then
    last="$now"
    since=$(date +%s)
  elif [ $(( $(date +%s) - since )) -ge "$QUIET" ]; then
    if [ "$EN" = 1 ]; then
      if [ "$QUIET" -ge 60 ]; then quiet="$((QUIET / 60)) min"; else quiet="$QUIET s"; fi
      echo "SILENCE $quiet: $now"
    else
      if [ "$QUIET" -ge 60 ]; then quiet="$((QUIET / 60)) мин"; else quiet="$QUIET с"; fi
      echo "ТИШИНА $quiet: $now"
    fi
    exit 0
  fi

  sleep "$EVERY"
done
