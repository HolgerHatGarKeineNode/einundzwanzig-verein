<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Der Verein führt keine Wahlen mehr über das Portal durch; die gesamte
     * Wahl-Funktion ist entfernt. Die Tabelle enthielt auf Produktion genau
     * einen Datensatz (Vorstandswahl 2024).
     */
    public function up(): void
    {
        Schema::dropIfExists('elections');
    }

    /**
     * Legt das Schema im Zustand wieder an, den die beiden entfernten
     * Migrationen `2024_09_28_181901_create_elections_table` und
     * `2024_09_29_143100_add_end_time_field_to_elections_table` zusammen
     * erzeugt hatten — deshalb steht `end_time` hier direkt mit drin.
     *
     * Die DATEN stellt diese Migration bewusst NICHT wieder her. Der einzige
     * Datensatz ist als Beleg in `docs/vereinshistorie/wahl-2024.md`
     * archiviert und kann von dort von Hand eingespielt werden. Die
     * abgegebenen Stimmen lagen nie in dieser Tabelle, sondern als
     * Nostr-Events (kind 32122 und 2121) auf dem Vereins-Relay.
     */
    public function down(): void
    {
        Schema::create('elections', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('year');
            $table->json('candidates');
            $table->timestamps();
            $table->timestamp('end_time')->nullable();
        });
    }
};
