#!/bin/sh
. /application/tools/dbinit.sh

echo "Обновление полнотекстовых индексов">/application/sql/status
$SQL_CMD -f /application/tools/update_vectors.sql

echo "">/application/sql/status
