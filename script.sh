#!/bin/bash

#TARGET="yaml_parse/target.php"
#CORPUS="corpus-yaml/"
#LOGFILE="yaml_parse/log/log.txt"
#OUTDIR="yaml_parse/output/"
TARGET="request_mix_3/target.php"
CORPUS="corpus/"
LOGFILE="request_mix_3/log/log.txt"
OUTDIR="request_mix_3/output/"
MEM_LIMIT_MB=300
MAX_RESTARTS=0            # 0 = безлимит
TOTAL_TIMEOUT_MINUTES=30  # общий таймаут работы фаззера

RESTARTS=0
START_TIME=$(date +%s)

while true; do
    echo "=== Fuzzer run $((RESTARTS+1)) ===" | tee -a "$LOGFILE"

    php bin/php-fuzzer fuzz "$TARGET" "$CORPUS" "$OUTDIR" "$LOGFILE" \
        --memory-limit=$MEM_LIMIT_MB

    CODE=$?
    NOW=$(date +%s)
    ELAPSED=$(( (NOW - START_TIME) / 60 ))

    # Проверка таймаута
    if [ $ELAPSED -ge $TOTAL_TIMEOUT_MINUTES ]; then
        echo "[!] Total timeout of $TOTAL_TIMEOUT_MINUTES minutes reached. Exiting." | tee -a "$LOGFILE"
        break
    fi

    # Проверка выхода
    if [ $CODE -eq 42 ]; then
        echo "[*] Memory limit hit. Restarting..." | tee -a "$LOGFILE"
        RESTARTS=$((RESTARTS+1))
    else
        echo "[*] Exiting with code $CODE" | tee -a "$LOGFILE"
        break
    fi

    # Проверка лимита перезапусков
    if [ $MAX_RESTARTS -gt 0 ] && [ $RESTARTS -ge $MAX_RESTARTS ]; then
        echo "[!] Maximum restarts ($MAX_RESTARTS) reached. Exiting." | tee -a "$LOGFILE"
        break
    fi

    sleep 2
done
