<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Fatture\Pages;

use App\Filament\God\Resources\Fatture\FatturaResource;
use App\Models\Fattura;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

class ListFatture extends ListRecords
{
    protected static string $resource = FatturaResource::class;

    public function getTitle(): string
    {
        return 'Fatture';
    }

    public function getSubheading(): ?string
    {
        return 'Nascono da sole quando una palestra paga. L\'XML si scarica solo da qui.';
    }

    protected function getHeaderActions(): array
    {
        return [
            /*
             * 💡 **Gli XML di un mese in un file solo**: è quello che chiede un
             * commercialista, e scaricarli uno per uno sarebbe il lavoro che
             * questa pagina deve togliere.
             */
            Action::make('xmlDelMese')
                ->label('XML di un mese (zip)')
                ->icon('heroicon-m-archive-box-arrow-down')
                ->color('gray')
                ->schema([
                    Select::make('mese')
                        ->label('Mese')
                        ->options(fn (): array => self::mesiConFatture())
                        ->required(),
                ])
                ->action(function (array $data): ?BinaryFileResponse {
                    $inizio = Carbon::createFromFormat('Y-m', (string) $data['mese'])->startOfMonth();

                    $fatture = Fattura::withoutGlobalScopes()
                        ->emesse()
                        ->whereBetween('data', [$inizio->toDateString(), $inizio->copy()->endOfMonth()->toDateString()])
                        ->orderBy('numero')
                        ->get();

                    if ($fatture->isEmpty()) {
                        Notification::make()->title('Nessuna fattura emessa in quel mese')->warning()->send();

                        return null;
                    }

                    $percorso = tempnam(sys_get_temp_dir(), 'fatture').'.zip';
                    $zip = new ZipArchive;
                    $zip->open($percorso, ZipArchive::CREATE | ZipArchive::OVERWRITE);

                    foreach ($fatture as $f) {
                        $zip->addFromString($f->nomeDelFileXml(), (string) $f->xml);
                    }

                    $zip->close();

                    return response()
                        ->download($percorso, 'fatture-'.$inizio->format('Y-m').'.zip', ['Content-Type' => 'application/zip'])
                        ->deleteFileAfterSend();
                }),
        ];
    }

    /** @return array<string, string> `2026-09` => «settembre 2026», dai mesi che hanno fatture */
    private static function mesiConFatture(): array
    {
        return Fattura::withoutGlobalScopes()
            ->emesse()
            ->orderByDesc('data')
            ->pluck('data')
            ->map(fn (Carbon $d): string => $d->format('Y-m'))
            ->unique()
            ->mapWithKeys(fn (string $m): array => [
                $m => Carbon::createFromFormat('Y-m', $m)->startOfMonth()->locale('it')->translatedFormat('F Y'),
            ])
            ->all();
    }
}
