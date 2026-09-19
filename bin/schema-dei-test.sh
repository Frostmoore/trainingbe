#!/usr/bin/env bash
# ---------------------------------------------------------------------------
# ⚡ Rigenera database/schema/mysql-schema.sql — 19/09/2026.
#
# I test creano il DB da questo file invece che da 90 migrazioni (~3,5 min →
# ~25 s prima del primo test). Vedi tests/bootstrap.php.
#
# 🚨 Lo schema di Laravel contiene SOLO la struttura e la tabella `migrations`.
# Le righe che una migrazione inserisce (i piani in `plans`) NON ci sono: senza,
# 71 test falliscono con «plan_id cannot be null». Per questo lo script:
#   1. rifà il DB di test dalle migrazioni vere (senza lo schema);
#   2. esegue `schema:dump`;
#   3. aggiunge i DATI delle tabelle seminate (TABELLE_SEMINATE);
#   4. ⛔ si ferma se una tabella con righe non è in quell'elenco — una
#      migrazione nuova che semina qualcosa deve finirci, o i test mentono.
#
# 💡 Quando rilanciarlo: non serve a ogni migrazione (Laravel carica lo schema
# e poi esegue quelle mancanti). Serve se una migrazione nuova INSERISCE dati,
# o quando le migrazioni fuori dallo schema diventano tante e rallentano.
#
# Uso (Git Bash, dalla radice di trainingbe):  bash bin/schema-dei-test.sh
# ⚠️ Tocca SOLO training_companion_test. Mai lanciarlo con una suite in corso.
# ---------------------------------------------------------------------------
set -euo pipefail

MYSQL_BIN="${MYSQL_BIN:-/e/coding/XAMPP/mysql/bin}"
PHP="${PHP:-E:/coding/php84/php.exe}"
DB=training_companion_test
TABELLE_SEMINATE="plans"
SCHEMA=database/schema/mysql-schema.sql

export PATH="$MYSQL_BIN:$PATH"
export DB_DATABASE="$DB"

[ -f "$SCHEMA" ] && mv "$SCHEMA" "$SCHEMA.vecchio"
ripristina() { [ -f "$SCHEMA.vecchio" ] && mv "$SCHEMA.vecchio" "$SCHEMA"; }
trap ripristina ERR

echo "1/4 migrate:fresh su $DB dalle migrazioni vere..."
"$PHP" artisan migrate:fresh --force >/dev/null

echo "2/4 controllo delle tabelle con righe..."
for t in $(mysql -uroot -N -e "select table_name from information_schema.tables where table_schema='$DB'" | tr -d '\r'); do
    # ⚠️ il client di Windows chiude le righe con \r: senza tr i nomi escono rotti
    n=$(mysql -uroot -N -e "select count(*) from \`$DB\`.\`$t\`" | tr -d '\r')
    [ "$n" = "0" ] && continue
    [ "$t" = "migrations" ] && continue
    if ! [[ " $TABELLE_SEMINATE " == *" $t "* ]]; then
        echo "⛔ '$t' ha $n righe dopo le migrazioni e non è in TABELLE_SEMINATE: aggiungila." >&2
        false
    fi
done

echo "3/4 schema:dump..."
"$PHP" artisan schema:dump >/dev/null 2>&1

echo "4/4 i dati di: $TABELLE_SEMINATE"
{
    echo
    echo "-- ⚡ Righe inserite dalle migrazioni (bin/schema-dei-test.sh)"
    mysqldump -uroot --no-create-info --skip-triggers --compact "$DB" $TABELLE_SEMINATE
} >> "$SCHEMA"

rm -f "$SCHEMA.vecchio"
echo "✅ $SCHEMA rigenerato"
