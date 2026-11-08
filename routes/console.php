<?php

// Equipo 7: expira reservas vencidas cada 5 min
Illuminate\Support\Facades\Schedule::command('e7:expire')->everyFiveMinutes();
Illuminate\Support\Facades\Schedule::command('team3:expire-reservations')->everyFiveMinutes();
Illuminate\Support\Facades\Schedule::command('reservations:expire')->everyFiveMinutes();
Schedule::command('team4:publish-outbox')->everyMinute();
Schedule::command('team4:generate-alerts')->everyFifteenMinutes();