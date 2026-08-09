<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\ResponseFactory as BaseResponseFactory;

/**
 * I numeri con la virgola restano numeri con la virgola.
 *
 * 🚨 **Perche' vale la pena sostituire una classe del framework per questo.**
 * `json_encode(30.0)` produce `30`, non `30.0`. Per PHP e' indifferente; per un
 * client tipizzato no. In Dart — cioe' nell'app che consumera' queste API —
 * `jsonDecode` restituisce un `int` in un caso e un `double` nell'altro, e un
 * `as double` va in crash. Il guasto e' peggio di un errore normale perche'
 * compare **solo quando il valore e' tondo**: un peso di 80,5 kg funziona, uno
 * di 80 kg fa esplodere la schermata.
 *
 * ⚠️ **E va deciso qui, alla codifica.** Un middleware che aggiunge l'opzione
 * dopo non serve a niente: `JsonResponse` conserva il JSON gia' codificato, e
 * rileggerlo per riscriverlo parte da un `30` che e' gia' diventato intero.
 * L'informazione va tenuta, non recuperata.
 *
 * Vale per tutte le risposte JSON dell'applicazione, non solo per l'API: i
 * pannelli non ne risentono, e una regola con un'eccezione e' una regola che
 * prima o poi qualcuno applica dalla parte sbagliata.
 */
class ResponseFactory extends BaseResponseFactory
{
    public function json($data = [], $status = 200, array $headers = [], $options = 0): JsonResponse
    {
        return parent::json($data, $status, $headers, $options | JSON_PRESERVE_ZERO_FRACTION);
    }
}
