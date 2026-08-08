@echo off
rem ---------------------------------------------------------------------------
rem PHP scoped al progetto — ADR-11 in plan_trainingbe.md.
rem
rem Laravel 13 richiede PHP >= 8.3. Il PHP di XAMPP e' 8.2.12 e NON basta.
rem Questo wrapper garantisce che ogni comando del progetto usi lo stesso
rem interprete anche se il PATH della sessione cambia.
rem
rem Uso:  bin\php.cmd artisan migrate
rem
rem Se il progetto migra su un'altra macchina, si cambia SOLO la riga qui sotto.
rem La versione attesa e' dichiarata in .php-version
rem ---------------------------------------------------------------------------
"E:\coding\php83\php.exe" %*
