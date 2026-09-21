<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 🔎 **Una condizione sui destinatari** — 24/09/2026, P5 di `plan_promozioni.md`
 * (opzioni 20-24).
 *
 * Le condizioni sono in **AND** fra loro e sopra i bersagli: servono a
 * restringere. Ognuna ha i suoi `parametri` (colonna json), detti da
 * [parametri]; il modulo mostra solo quelli.
 *
 * | Caso | Parametri | Vera quando |
 * |---|---|---|
 * | `abbonamento` | `stato` | abbonato · non abbonato · ex abbonato · in prova · pagamento fallito |
 * | `registrato_da_meno_di` | `giorni` | l'account ha meno di N giorni |
 * | `registrato_dopo` | `data` | l'account è nato dopo quella data |
 * | `cliente_da_piu_di` | `mesi` | paga un abbonamento da più di N mesi |
 * | `piano_attuale` | `plan_ids` | il suo piano adesso è uno di quelli |
 * | `inattivo_da` | `giorni` | non accede all'app da N giorni (o mai) |
 * | `mai_comprato_gettoni` | — | nessun acquisto di gettoni |
 * | `gettoni_finiti` | — | saldo a zero |
 * | `gettoni_comprati_almeno` | `gettoni` | ne ha comprati almeno N, in tutto |
 */
enum TipoDiCondizione: string
{
    case Abbonamento = 'abbonamento';
    case RegistratoDaMenoDi = 'registrato_da_meno_di';
    case RegistratoDopo = 'registrato_dopo';
    case ClienteDaPiuDi = 'cliente_da_piu_di';
    case PianoAttuale = 'piano_attuale';
    case InattivoDa = 'inattivo_da';
    case MaiCompratoGettoni = 'mai_comprato_gettoni';
    case GettoniFiniti = 'gettoni_finiti';
    case GettoniComprati = 'gettoni_comprati_almeno';

    /** I valori di `parametri.stato` per `abbonamento`. */
    public const STATI_ABBONAMENTO = [
        'abbonato' => 'Abbonati (piano non gratuito)',
        'non_abbonato' => 'Non abbonati (piano gratuito)',
        'ex_abbonato' => 'Ex abbonati (hanno pagato, ora no)',
        'in_prova' => 'In prova gratuita',
        'pagamento_fallito' => 'Con un pagamento fallito',
    ];

    public function label(): string
    {
        return match ($this) {
            self::Abbonamento => 'Per abbonamento',
            self::RegistratoDaMenoDi => 'Nuovi iscritti (da meno di N giorni)',
            self::RegistratoDopo => 'Iscritti dopo una data',
            self::ClienteDaPiuDi => 'Clienti fedeli (da più di N mesi)',
            self::PianoAttuale => 'Con un certo piano',
            self::InattivoDa => 'Inattivi da N giorni',
            self::MaiCompratoGettoni => 'Mai comprato gettoni',
            self::GettoniFiniti => 'Gettoni finiti',
            self::GettoniComprati => 'Hanno comprato almeno N gettoni',
        };
    }

    /**
     * Le chiavi di `parametri` che questo tipo pretende.
     *
     * @return list<string>
     */
    public function parametri(): array
    {
        return match ($this) {
            self::Abbonamento => ['stato'],
            self::RegistratoDaMenoDi, self::InattivoDa => ['giorni'],
            self::RegistratoDopo => ['data'],
            self::ClienteDaPiuDi => ['mesi'],
            self::PianoAttuale => ['plan_ids'],
            self::GettoniComprati => ['gettoni'],
            self::MaiCompratoGettoni, self::GettoniFiniti => [],
        };
    }

    public function usa(string $parametro): bool
    {
        return in_array($parametro, $this->parametri(), true);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $out = [];

        foreach (self::cases() as $caso) {
            $out[$caso->value] = $caso->label();
        }

        return $out;
    }
}
