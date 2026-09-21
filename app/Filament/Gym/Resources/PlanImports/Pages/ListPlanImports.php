<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\PlanImports\Pages;

use App\Enums\AiFeature;
use App\Filament\Gym\Resources\PlanImports\PlanImportResource;
use App\Jobs\ParseWorkoutPdf;
use App\Models\User;
use App\Models\WorkoutPlanImport;
use App\Services\Ai\CancelloDeiGettoni;
use App\Services\Billing\Exceptions\GettoniEsauritiException;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Exceptions\Halt;

class ListPlanImports extends ListRecords
{
    protected static string $resource = PlanImportResource::class;

    /**
     * 💡 Scritto a mano: dal nome della risorsa Filament ricavava «Import Da
     * PDF», con le maiuscole all'inglese — e dal 23/09/2026 (F7) si importano
     * anche le foto.
     */
    public function getTitle(): string
    {
        return 'Schede da importare';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Carica una scheda')
                ->modalHeading('Importa una scheda da un PDF o da una foto')
                /*
                 * ⚠️ **Il prezzo si dice prima, non si scopre dal saldo** —
                 * U.6.3. E' la stessa regola gia' scritta per il piano
                 * alimentare, e vale ancora di piu' qui: fino a ieri questo
                 * import era gratis, quindi chi lo usa **non si aspetta** che
                 * costi. Un addebito silenzioso su un'abitudine consolidata e'
                 * il modo piu' rapido per farsi accusare di averlo nascosto.
                 */
                ->modalDescription('Costa 50 gettoni AI, che si scalano solo se la lettura riesce.')
                ->modalSubmitActionLabel('Carica e leggi')
                ->using(function (array $data): WorkoutPlanImport {
                    /*
                     * 🚨 **Il cancello PRIMA di scrivere il file.**
                     *
                     * Due ragioni, e la seconda e' quella vera:
                     *
                     *  1. aprire l'import e poi scoprire che i gettoni non
                     *     bastano lascerebbe sul nostro disco il PDF della
                     *     scheda di una persona, per niente;
                     *  2. ⛔ **fino a U.6 questo cancello non c'era affatto** —
                     *     `AiFeature::PdfImport` aveva un prezzo che nessuno
                     *     riscuoteva. Il listino diceva 10, l'incasso era zero,
                     *     e non c'era nessun errore da nessuna parte: e' il modo
                     *     in cui un prezzo sbagliato non si vede.
                     */
                    $utente = auth()->user();

                    try {
                        $conGettoni = app(CancelloDeiGettoni::class)->apri(
                            $utente instanceof User ? $utente : null,
                            AiFeature::PdfImport,
                        );
                    } catch (GettoniEsauritiException $e) {
                        /*
                         * ⚠️ **Si dice quanti ne servono e quanti ce ne sono.**
                         * «Gettoni insufficienti» da solo non dice quanto
                         * ricaricare, ed e' gia' il motivo per cui
                         * l'eccezione porta con se' i due numeri.
                         */
                        Notification::make()
                            ->title('Gettoni insufficienti')
                            ->body("Servono {$e->servivano} gettoni e ne hai {$e->saldo}.")
                            ->danger()
                            ->persistent()
                            ->send();

                        throw new Halt;
                    }

                    $import = WorkoutPlanImport::create([
                        'uploaded_by' => auth()->id(),
                        // ⛔ Sempre `null`: da un import esce un **modello**.
                        // Vedi la nota in `PlanImportResource`.
                        'member_id' => null,
                        'paga_con_gettoni' => $conGettoni,
                    ]);

                    /*
                     * Il file arriva come oggetto caricato (`storeFiles(false)`)
                     * e va a medialibrary.
                     *
                     * 🚨 **Corretto il 23/09/2026 (F7): qui c'era scritto il
                     * falso.** Il commento diceva *«non sul disco pubblico»*, ma
                     * medialibrary senza `useDisk()` usa **proprio** `public`:
                     * la scheda di una persona era raggiungibile per URL, con id
                     * sequenziali. ✅ Adesso il disco privato lo impone la
                     * collezione (`WorkoutPlanImport::registerMediaCollections`),
                     * e non dipende più da chi scrive questa riga.
                     */
                    $import->addMedia($data['pdf']->getRealPath())
                        ->usingFileName($data['pdf']->getClientOriginalName())
                        ->toMediaCollection(WorkoutPlanImport::COLLECTION);

                    ParseWorkoutPdf::dispatch($import->id);

                    Notification::make()
                        ->title('PDF in lettura')
                        ->body('Quando e\' pronto lo trovi qui come «Da rivedere».')
                        ->success()
                        ->send();

                    return $import;
                }),
        ];
    }
}
