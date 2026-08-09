@echo off
rem ---------------------------------------------------------------------------
rem PHP scoped al progetto — ADR-11 e ADR-13 in plan_trainingbe.md.
rem
rem Versione: 8.4.24, la STESSA che gira su test-server (staging).
rem L'allineamento non e' un vezzo: finche' locale e staging divergevano, un
rem errore introdotto da 8.4 si scopriva soltanto online, cioe' nel posto in cui
rem ripararlo costa di piu'.
rem
rem Il PHP di XAMPP e' 8.2.12 e NON basta: Laravel 13 vuole >= 8.3.
rem Questo wrapper garantisce che ogni comando del progetto usi lo stesso
rem interprete anche se il PATH della sessione cambia.
rem
rem Uso:  bin\php.cmd artisan migrate
rem
rem Se il progetto migra su un'altra macchina, si cambia SOLO la riga qui sotto.
rem La versione attesa e' dichiarata in .php-version
rem ---------------------------------------------------------------------------
"E:\coding\php84\php.exe" %*
