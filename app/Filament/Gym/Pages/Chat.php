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

/**
 * La chat lato staff — B8.3.
 *
 * 🚨 **Il trainer vede solo le proprie conversazioni.** Non e' una scelta di
 * interfaccia: `Conversation::forUser()` filtra su `trainer_id`/`member_id`, e
 * un elenco che mostrasse tutti i thread della palestra darebbe a ogni trainer
 * la corrispondenza dei colleghi con i loro clienti — che spesso contiene
 * infortuni e questioni personali.
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

    public function getActiveProperty(): ?Conversation
    {
        if ($this->conversationId === null) {
            return null;
        }

        return Conversation::query()
            ->forUser(auth()->user())
            ->with('messages.sender')
            ->find($this->conversationId);
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
