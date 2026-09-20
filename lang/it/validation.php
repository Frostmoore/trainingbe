<?php

declare(strict_types=1);

/*
 * 🈯 **I messaggi di validazione, in italiano** — 20/09/2026.
 *
 * ⛔ Non c'era **nessun** file di lingua: Laravel ne porta solo `en`, e con
 * `APP_LOCALE=it` e `APP_FALLBACK_LOCALE=it` non resta niente su cui ripiegare.
 * 🚨 Risultato, visto sull'emulatore: l'app mostrava **«validation.array»** al
 * posto di una frase. Ogni errore di validazione senza messaggio scritto a mano
 * usciva così — e ce ne sono in tutta l'applicazione.
 *
 * 💡 I messaggi scritti a mano nelle FormRequest continuano a vincere su questi:
 * qui c'è la rete, non la regola.
 *
 * ⚠️ Solo le regole che il progetto usa davvero, più quelle comuni: un file
 * completo copiato da internet invecchia e non lo rilegge nessuno. Se una regola
 * nuova esce senza traduzione si vede subito, perché a schermo compare la chiave.
 */

return [
    'accepted' => 'Devi accettare :attribute.',
    'active_url' => ':attribute non è un indirizzo valido.',
    'after' => ':attribute deve essere una data successiva a :date.',
    'after_or_equal' => ':attribute deve essere una data uguale o successiva a :date.',
    'alpha' => ':attribute può contenere solo lettere.',
    'alpha_dash' => ':attribute può contenere solo lettere, numeri, trattini e trattini bassi.',
    'alpha_num' => ':attribute può contenere solo lettere e numeri.',
    'array' => ':attribute deve essere un elenco.',
    'before' => ':attribute deve essere una data precedente a :date.',
    'before_or_equal' => ':attribute deve essere una data uguale o precedente a :date.',
    'between' => [
        'array' => ':attribute deve avere fra :min e :max elementi.',
        'file' => ':attribute deve essere fra :min e :max kilobyte.',
        'numeric' => ':attribute deve essere fra :min e :max.',
        'string' => ':attribute deve avere fra :min e :max caratteri.',
    ],
    'boolean' => ':attribute può essere solo vero o falso.',
    'confirmed' => 'La conferma di :attribute non coincide.',
    'current_password' => 'La password non è corretta.',
    'date' => ':attribute non è una data valida.',
    'date_equals' => ':attribute deve essere una data uguale a :date.',
    'date_format' => ':attribute non corrisponde al formato :format.',
    'declined' => 'Devi rifiutare :attribute.',
    'different' => ':attribute e :other devono essere diversi.',
    'digits' => ':attribute deve avere :digits cifre.',
    'digits_between' => ':attribute deve avere fra :min e :max cifre.',
    'dimensions' => 'Le dimensioni di :attribute non vanno bene.',
    'distinct' => 'In :attribute c\'è un valore ripetuto.',
    'email' => ':attribute non è un indirizzo email valido.',
    'ends_with' => ':attribute deve finire con uno di questi valori: :values.',
    'enum' => 'Il valore di :attribute non è fra quelli ammessi.',
    'exists' => 'Il valore di :attribute non esiste.',
    'file' => ':attribute deve essere un file.',
    'filled' => 'Non puoi lasciare vuoto :attribute.',
    'gt' => [
        'array' => ':attribute deve avere più di :value elementi.',
        'file' => ':attribute deve superare :value kilobyte.',
        'numeric' => ':attribute deve essere maggiore di :value.',
        'string' => ':attribute deve avere più di :value caratteri.',
    ],
    'gte' => [
        'array' => ':attribute deve avere almeno :value elementi.',
        'file' => ':attribute deve essere di almeno :value kilobyte.',
        'numeric' => ':attribute deve essere maggiore o uguale a :value.',
        'string' => ':attribute deve avere almeno :value caratteri.',
    ],
    'image' => ':attribute deve essere un\'immagine.',
    'in' => 'Il valore di :attribute non è fra quelli ammessi.',
    'in_array' => 'Il valore di :attribute non è presente in :other.',
    'integer' => ':attribute deve essere un numero intero.',
    'ip' => ':attribute deve essere un indirizzo IP.',
    'json' => ':attribute deve essere una stringa JSON valida.',
    'lt' => [
        'array' => ':attribute deve avere meno di :value elementi.',
        'file' => ':attribute deve stare sotto :value kilobyte.',
        'numeric' => ':attribute deve essere minore di :value.',
        'string' => ':attribute deve avere meno di :value caratteri.',
    ],
    'lte' => [
        'array' => ':attribute non può avere più di :value elementi.',
        'file' => ':attribute non può superare :value kilobyte.',
        'numeric' => ':attribute deve essere minore o uguale a :value.',
        'string' => ':attribute non può avere più di :value caratteri.',
    ],
    'max' => [
        'array' => ':attribute non può avere più di :max elementi.',
        'file' => ':attribute non può superare :max kilobyte.',
        'numeric' => ':attribute non può essere maggiore di :max.',
        'string' => ':attribute non può avere più di :max caratteri.',
    ],
    'mimes' => ':attribute deve essere un file di tipo: :values.',
    'mimetypes' => ':attribute deve essere un file di tipo: :values.',
    'min' => [
        'array' => ':attribute deve avere almeno :min elementi.',
        'file' => ':attribute deve essere di almeno :min kilobyte.',
        'numeric' => ':attribute deve essere almeno :min.',
        'string' => ':attribute deve avere almeno :min caratteri.',
    ],
    'not_in' => 'Il valore di :attribute non è ammesso.',
    'not_regex' => 'Il formato di :attribute non è valido.',
    'numeric' => ':attribute deve essere un numero.',
    'password' => [
        'letters' => 'La password deve contenere almeno una lettera.',
        'mixed' => 'La password deve contenere una lettera maiuscola e una minuscola.',
        'numbers' => 'La password deve contenere almeno un numero.',
        'symbols' => 'La password deve contenere almeno un simbolo.',
        'uncompromised' => 'Questa password è comparsa in una fuga di dati: scegline un\'altra.',
    ],
    'present' => 'Manca :attribute.',
    'prohibited' => 'Non puoi indicare :attribute.',
    'prohibited_if' => 'Non puoi indicare :attribute quando :other vale :value.',
    'regex' => 'Il formato di :attribute non è valido.',
    'required' => 'Manca :attribute.',
    'required_if' => 'Manca :attribute, che serve quando :other vale :value.',
    'required_with' => 'Manca :attribute, che serve insieme a :values.',
    'required_without' => 'Manca :attribute, che serve quando non c\'è :values.',
    'same' => ':attribute e :other devono coincidere.',
    'size' => [
        'array' => ':attribute deve contenere :size elementi.',
        'file' => ':attribute deve essere di :size kilobyte.',
        'numeric' => ':attribute deve essere :size.',
        'string' => ':attribute deve avere :size caratteri.',
    ],
    'starts_with' => ':attribute deve cominciare con uno di questi valori: :values.',
    'string' => ':attribute deve essere un testo.',
    'timezone' => ':attribute deve essere un fuso orario valido.',
    'unique' => ':attribute è già in uso.',
    'uploaded' => 'Il caricamento di :attribute non è riuscito: riprova.',
    'url' => ':attribute non è un indirizzo valido.',
    'uuid' => ':attribute deve essere un UUID valido.',

    /*
     * 💡 I nomi dei campi come li chiama chi legge, non come li chiama il
     * database: «file» va bene, «consenso_documento» no.
     */
    'attributes' => [
        'consenso_documento' => 'il consenso a mandare il documento',
        'dichiarazione' => 'la dichiarazione',
        'email' => 'l\'email',
        'file' => 'il documento',
        'genere' => 'il tipo di documento',
        'login' => 'l\'email o il nome utente',
        'password' => 'la password',
        'password_confirmation' => 'la conferma della password',
        'phone' => 'il telefono',
        'username' => 'il nome utente',
    ],

    /*
     * 💡 Dove la frase generica suonerebbe storta, si scrive quella giusta.
     * ⚠️ `file` qui è un **elenco** di documenti: «il documento deve essere un
     * elenco» non vuol dire niente per chi lo legge.
     */
    'custom' => [
        'file' => [
            'required' => 'Serve almeno un documento.',
            'array' => 'Serve almeno un documento.',
            'min' => 'Serve almeno un documento.',
            'max' => 'Puoi caricare al massimo :max documenti in una volta.',
        ],
    ],
];
