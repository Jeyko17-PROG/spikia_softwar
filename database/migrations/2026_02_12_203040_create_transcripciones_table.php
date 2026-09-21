<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('transcripciones', function (Blueprint $table) {
            $table->id();
            $table->string('slug'); // para el canal
            $table->text('texto'); // texto traducido
            $table->string('idioma')->default('es'); // idioma del mensaje
            $table->string('audio_url')->nullable(); // audio si existe
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('transcripciones');
    }
};

