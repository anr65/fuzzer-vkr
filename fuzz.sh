#!/bin/bash

TARGET_FILE="request_mix_3/target.php"
CORPUS_DIR="corpus/"
MEMORY_LIMIT_MB=300
MAX_DURATION_MINUTES=60

START_TIME=$(date +%s)
MAX_DURATION_SECONDS=$((MAX_DURATION_MINUTES * 60))

while true; do
    CURRENT_TIME=$(date +%s)
    ELAPSED_TIME=$((CURRENT_TIME - START_TIME))

    if (( ELAPSED_TIME >= MAX_DURATION_SECONDS )); then
        echo "[✓] Время работы истекло (${MAX_DURATION_MINUTES} минут). Завершаем фаззинг."
        break
    fi

    echo "[*] Запуск фаззера..."
    php bin/php-fuzzer fuzz "$TARGET_FILE" "$CORPUS_DIR" &
    FUZZER_PID=$!

    while kill -0 $FUZZER_PID 2> /dev/null; do
        MEM_USAGE_KB=$(ps -o rss= -p $FUZZER_PID)
        MEM_USAGE_MB=$((MEM_USAGE_KB / 1024))

        if (( MEM_USAGE_MB > MEMORY_LIMIT_MB )); then
            echo "[!] PID $FUZZER_PID превышает $MEMORY_LIMIT_MB MB ($MEM_USAGE_MB MB). Перезапуск..."
            kill -9 $FUZZER_PID
            break
        fi

        # Дополнительная проверка общего времени внутри вложенного цикла
        CURRENT_TIME=$(date +%s)
        ELAPSED_TIME=$((CURRENT_TIME - START_TIME))
        if (( ELAPSED_TIME >= MAX_DURATION_SECONDS )); then
            echo "[✓] Время работы истекло (${MAX_DURATION_MINUTES} минут). Завершаем фаззинг."
            kill -9 $FUZZER_PID
            exit 0
        fi

        sleep 2
    done

    echo "[*] Ожидание 1 секунду перед перезапуском..."
    sleep 1
done
