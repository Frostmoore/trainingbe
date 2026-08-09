<?php

declare(strict_types=1);

namespace App\Filament\Gym\Pages;

use App\Events\MessageSent;
use App\Models\Conversation;
use App\Models\User;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * La chat lato staff — B8.3.
 *
 * 🚨 **Si vedono SOLO le proprie conversazioni. Vale per il trainer e vale, allo
 * stesso modo, per il titolare della palestra.**
 *
 * Non è una scelta di interfaccia. Quello che un iscritto scrive al proprio
 * trainer riguarda infortuni, disturbi alimentari, gravidanze, umore: non è
 * materiale che il titolare abbia titolo di leggere, e **sapere che potrebbe**
 * basta a far smettere le persone di scrivere le cose che contano — cioè a
 * rendere la chat inutile per quello per cui esiste.
 *
 * Un `gym_admin` che non sia anche parte di una conversazione apre questa pagina
 * e trova un elenco vuoto, con scritto il perché. La regola completa, e il
 * motivo per cui è **l'unica policy senza scorciatoia per il super admin**, sta
 * in `App\Policies\ConversationPolicy`.
 *
 * Aggiorna con un `poll` e non con un socket: nel pannello la latenza di
 * qualche secondo non cambia niente, e legare una pagina Filament a Reverb
 * significherebbe che la chat dello staff smette di funzionare ogni volta che il
 * processo WebSocket ha un problema. Il socket serve all'app, dove conta.
 */
class Chat extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static ?string $navigationLabel = 'Messaggi';

    protected static ?string $title = 'Messaggi';

    protected static ?int $navigationSort = 6;

    protected string $view = 'filament.gym.pages.chat';

    public ?int $conversationId = null;

    public string $body = '';

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u instanceof User && ($u->isGymAdmin() || $u->isTrainer());
    }

    public static function getNavigationBadge(): ?string
    {
        $u = auth()->user();

        if (! $u instanceof User) {
            return null;
        }

        $n = Conversation::query()->forUser($u)->get()
            ->sum(fn (Conversation $c): int => $c->unreadFor($u));

        return $n > 0 ? (string) $n : null;
    }

    /** @return Collection<int, Conversation> */
    public function getConversationsProperty(): Collection
    {
        return Conversation::query()
            ->forUser(auth()->user())
            ->with(['trainer', 'member'])
            ->recentFirst()
            ->get();
    }

    /**
     * 🚨 Due serrature, come nell'API: lo scope e la policy.
     *
     * La seconda è ridondante oggi e serve perché domani non lo sia: se qualcuno
     * cambiasse la query qui sopra, `ConversationPolicy` resterebbe a dire di no.
     * Quello che le persone si scrivono non deve dipendere da una sola riga.
     */
    public function getActiveProperty(): ?Conversation
    {
        if ($this->conversationId === null) {
            return null;
        }

        $conversazione = Conversation::query()
            ->forUser(auth()->user())
            ->with('messages.sender')
            ->find($this->conversationId);

        if ($conversazione === null || Gate::denies('view', $conversazione)) {
            return null;
        }

        return $conversazione;
    }

    /** Il titolare che non partecipa a nessun filo: gli si dice perché. */
    public function getSpiegazioneVuotoProperty(): string
    {
        $u = auth()->user();

        return $u instanceof User && $u->isGymAdmin() && ! $u->isTrainer()
            ? 'Qui compaiono soltanto le conversazioni a cui partecipi tu. '
              .'Quelle fra i tuoi trainer e i loro iscritti sono riservate: '
              .'contengono infortuni e questioni personali, e non sono visibili dal pannello.'
            : 'Nessuna conversazione. Le apre l\'iscritto dall\'app, oppure tu dalla sua scheda.';
    }

    public function apri(int $id): void
    {
        $this->conversationId = $id;

        $conversazione = $this->getActiveProperty();

        // Aprire il thread lo segna come letto: e' cio' che l'utente si aspetta,
        // e un pulsante «segna come letto» separato non lo preme nessuno.
        $conversazione?->messages()
            ->where('sender_id', '!=', auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    public function invia(): void
    {
        $conversazione = $this->getActiveProperty();
        $testo = trim($this->body);

        if ($conversazione === null || $testo === '') {
            return;
        }

        $messaggio = $conversazione->messages()->create([
            'sender_id' => auth()->id(),
            'body' => mb_substr($testo, 0, 4000),
        ]);

        $this->body = '';

        // Dopo il salvataggio: se il broadcast fallisse, il messaggio esiste
        // comunque e l'app lo trova col polling.
        MessageSent::announce($messaggio);
    }
}
