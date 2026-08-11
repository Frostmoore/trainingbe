<?php

declare(strict_types=1);

namespace App\Filament\Gym\Pages;

use App\Models\Conversation;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Gate;

/**
 * La chat non si legge più dal pannello — S6.8.
 *
 * ── Perché questa pagina è diventata un cartello ───────────────────────────
 *
 * 🚨 **Era una pagina Livewire resa dal server.** Per disegnare i messaggi, il
 * server doveva leggerli — e da S6 non può più: `messages.body` contiene una
 * busta cifrata fra due telefoni, di cui qui non esiste nessuna chiave.
 *
 * Le alternative erano due:
 * 1. portare la crittografia **nel browser** — un progetto a sé, con le chiavi
 *    private da custodire in un contesto (una scheda del browser, su un PC della
 *    reception) molto peggiore di un telefono;
 * 2. spostare la chat **in app**, dove le chiavi già stanno.
 *
 * È stata scelta la seconda (decisione D6, e coincide con il requisito B11: il
 * trainer avrà comunque un'interfaccia doppia nell'app).
 *
 * ⚠️ **La pagina resta al suo posto e spiega**, invece di sparire. Chi ci arriva
 * da un segnalibro o dal menù deve leggere *«la chat è nell'app»*, non trovarsi
 * un 404 e pensare che il pannello sia rotto.
 *
 * ✅ **Il conteggio dei non letti resta e continua a funzionare** — conta righe,
 * non legge testo. È la dimostrazione pratica che tutto ciò che il server deve
 * ancora fare, lo fa sui metadati.
 */
class Chat extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'Messaggi';

    protected static ?string $title = 'Messaggi';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.gym.pages.chat';

    /**
     * 🚨 **Chiusa del tutto durante un'impersonazione.**
     *
     * Non «vuota»: proprio non raggiungibile, e senza voce nel menù. Durante
     * un'impersonazione `auth()->user()` è la persona impersonata, quindi ogni
     * altro controllo la considererebbe legittimamente padrona delle proprie
     * conversazioni — che è esattamente il contrario di quello che serve.
     *
     * ⚠️ Resta **anche adesso** che i messaggi sono illeggibili: il conteggio
     * dei non letti e l'elenco di con chi si parla sono già informazioni, e non
     * è materiale che una sessione impersonata debba vedere.
     */
    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u instanceof User
            && ($u->isGymAdmin() || $u->isTrainer())
            && Gate::allows('viewAny', Conversation::class);
    }

    /**
     * Quanti messaggi aspettano — l'unica cosa che il server sa ancora dire.
     *
     * 💡 Serve proprio perché la chat è altrove: senza, un trainer che vive nel
     * pannello non avrebbe **nessun** segnale che qualcuno gli ha scritto, e
     * scoprirebbe i messaggi solo aprendo l'app per caso.
     */
    public static function getNavigationBadge(): ?string
    {
        $u = auth()->user();

        if (! $u instanceof User || Gate::denies('viewAny', Conversation::class)) {
            return null;
        }

        $n = Conversation::query()->forUser($u)->get()
            ->sum(fn (Conversation $c): int => $c->unreadFor($u));

        return $n > 0 ? (string) $n : null;
    }

    /** Quante conversazioni hanno qualcosa da leggere, per il testo del cartello. */
    public function getNonLettiProperty(): int
    {
        $u = auth()->user();

        if (! $u instanceof User || Gate::denies('viewAny', Conversation::class)) {
            return 0;
        }

        return (int) Conversation::query()->forUser($u)->get()
            ->sum(fn (Conversation $c): int => $c->unreadFor($u));
    }
}
