<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🗑️ **Via la tabella dei profili** — 19/09/2026.
 *
 * 📌 Il committente: *«puoi togliere anche le note dello staff, non servono a
 * molto sul server»*.
 *
 * 💡 Dopo `2026_09_19_150000` in `profiles` restavano solo le note dello staff
 * («infortuni, limitazioni»), che potevano riguardare la salute. Tolte quelle,
 * la tabella non conteneva più niente: si toglie tutta. Il profilo sta sul
 * telefono (`ProfiloSulTelefono` nell'app). Note su staging al momento: 0.
 *
 * ⛔ Il `down()` la rimette **vuota**, com'era dopo `150000`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('profiles');
    }

    public function down(): void
    {
        Schema::create('profiles', function (Blueprint $tabella): void {
            $tabella->id();
            $tabella->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $tabella->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $tabella->text('notes')->nullable();
            $tabella->timestamps();
            $tabella->index('tenant_id');
        });
    }
};
