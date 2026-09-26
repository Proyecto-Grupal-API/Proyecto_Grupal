<?php

namespace App\Services;

use App\Models\Campana;
use App\Models\ConvocatoriaBeca;
use App\Models\Evento;
use App\Models\MiembroOrganizacion;
use App\Models\PreferenciaComunicacion;
use App\Models\RegistroEvento;
use App\Models\SolicitudBeca;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class AudienciaCampana
{
    public function ids(Campana $c): array
    {
        if ($c->audiencia === 'miembros') {
            $ids = MiembroOrganizacion::activos()->where('organizacion_id', $c->organizacion_id)->pluck('usuario_id');
        } elseif (in_array($c->audiencia, ['evento_confirmados', 'evento_espera'])) {
            Evento::where('organizacion_id', $c->organizacion_id)->findOrFail($c->referencia_id);
            $ids = RegistroEvento::where('evento_id', $c->referencia_id)->where('estado', $c->audiencia === 'evento_confirmados' ? 'confirmada' : 'espera')->pluck('usuario_id');
        } else {
            ConvocatoriaBeca::where('organizacion_id', $c->organizacion_id)->findOrFail($c->referencia_id);
            $q = SolicitudBeca::where('convocatoria_id', $c->referencia_id)->whereNotNull('enviado_en');
            $q->whereIn('estado', $c->audiencia === 'beca_aprobadas' ? ['aprobada'] : ['pendiente', 'en_revision', 'aprobada', 'rechazada']);
            $ids = $q->pluck('usuario_id');
        }
        $silenciados = PreferenciaComunicacion::where('organizacion_id', $c->organizacion_id)->where('silenciada', true)->pluck('usuario_id');
        $result = User::whereIn('_id', $ids->diff($silenciados)->unique()->values())->orderBy('_id')->limit(5001)->pluck('id')->map(fn ($id) => (string) $id)->all();
        if (count($result) > 5000) {
            throw ValidationException::withMessages(['audiencia' => 'Esta entrega admite hasta 5000 destinatarios por campaña.']);
        }
        sort($result, SORT_STRING);

        return $result;
    }

    public function firma(Campana $c, array $ids): string
    {
        return hash_hmac('sha256', json_encode([(string) $c->id, $c->revision, $ids]), config('app.key'));
    }

    public function enlace(Campana $c, string $usuario): ?string
    {
        return match ($c->accion) {
            'eventos' => '/modulo6/eventos','becas' => '/modulo6/becas','mis_boletos' => '/modulo6/mis-boletos',
            'boleto_evento' => '/modulo6/eventos/'.$c->referencia_id.'/boleto',
            'solicitud_beca' => ($s = SolicitudBeca::where('convocatoria_id', $c->referencia_id)->where('usuario_id', $usuario)->first()) ? '/modulo6/becas/solicitudes/'.$s->id : null,
            default => null,
        };
    }
}
