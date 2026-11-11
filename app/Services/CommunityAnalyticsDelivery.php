<?php

namespace App\Services;

use App\Models\{ReporteTransparencia,Encuesta,Eleccion,RespuestaEncuesta,Voto};
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Operation\FindOneAndUpdate;
use Ramsey\Uuid\Uuid;

/** Only E6 aggregates cross the API. No roster, answer, title or personal document. */
final class CommunityAnalyticsDelivery
{
    private const METRICS = [
        'eventos_iniciados','eventos_cancelados','reservas_confirmadas','reservas_espera','reservas_pago_pendiente','asistencias',
        'eventos_cobros_confirmados','eventos_cobrado_bruto_centavos','eventos_devoluciones_confirmadas','eventos_devuelto_centavos','eventos_cobrado_neto_centavos',
        'solicitudes_enviadas','solicitudes_pendiente','solicitudes_en_revision','solicitudes_aprobada','solicitudes_rechazada','solicitudes_retirada','solicitudes_cancelada',
        'aprobaciones_periodo','personas_aprobadas','asignaciones_pendientes','monto_pendiente_centavos','apoyos_entregados',
        'apoyos_entregados_locker','apoyos_entregados_impresiones','apoyos_entregados_bono','mensajes_entregados','mensajes_leidos',
        'encuestas_cerradas','encuestas_participaciones','votaciones_cerradas','votaciones_participaciones',
    ];

    public function deliver(int $limit = 30): array
    {
        $result = ['enabled'=>config('platform.community_analytics.enabled') === true,'queued'=>0,'sent'=>0,'failed'=>0,'invalid'=>0];
        if (! $result['enabled']) { return $result; }
        if ($limit < 1 || $limit > 100) { throw new InvalidArgumentException('El límite debe estar entre 1 y 100.'); }
        $raw = config('platform.community_analytics.since');
        if (! is_string($raw) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/', $raw)) {
            throw new InvalidArgumentException('Configura PLATFORM_COMMUNITY_ANALYTICS_SINCE con fecha ISO y zona.');
        }
        try { $since = new DateTimeImmutable($raw); $errors = DateTimeImmutable::getLastErrors(); }
        catch (\Exception) { throw new InvalidArgumentException('Corte analítico inválido.'); }
        if ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) { throw new InvalidArgumentException('Corte analítico inválido.'); }
        $since = CarbonImmutable::instance($since)->utc();
        $outbox = DB::connection('mongodb')->getCollection('community_analytics_deliveries');
        // ponytail: scan own source records at course scale; add a persistent scan cursor for a larger deployment.
        foreach ($this->sources() as $source) {
            try { $events = $source instanceof ReporteTransparencia ? $this->report($source) : $this->consultation($source); }
            catch (\Exception) { ++$result['invalid']; continue; }
            foreach ($events as $event) {
                $date = CarbonImmutable::parse($event['occurred_at']);
                if ($date->lt($since) || $date->isFuture()) { continue; }
                $insert = $outbox->updateOne(['event_id'=>$event['event_id']], ['$setOnInsert'=>[
                    'envelope'=>$event,'status'=>'pending','attempts'=>0,'next_attempt_at'=>new UTCDateTime(now()),'created_at'=>new UTCDateTime(now()),
                ]], ['upsert'=>true]);
                $result['queued'] += $insert->getUpsertedCount();
                if ($result['queued'] >= $limit) { break 2; }
            }
        }
        $now = new UTCDateTime(now());
        $due = ['status'=>'pending','next_attempt_at'=>['$lte'=>$now], '$or'=>[['locked_until'=>null],['locked_until'=>['$lte'=>$now]]]];
        foreach ($outbox->find($due, ['sort'=>['next_attempt_at'=>1,'_id'=>1],'limit'=>$limit]) as $candidate) {
            $claim = (string) Uuid::uuid4();
            $row = $outbox->findOneAndUpdate(['_id'=>$candidate['_id'], ...$due], ['$set'=>[
                'lease_token'=>$claim,'locked_until'=>new UTCDateTime(now()->addSeconds(45)),
            ], '$inc'=>['attempts'=>1]], ['returnDocument'=>FindOneAndUpdate::RETURN_DOCUMENT_AFTER]);
            if (! $row) { continue; }
            $body = json_decode(json_encode($row['envelope'], JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            try { $error = app(CommunityPlatformDelivery::class)->sendEnvelope($body,'/api/v1/plataforma/eventos'); }
            catch (\Throwable) { $error = 'transport_unavailable'; }
            $changes = ['lease_token'=>null,'locked_until'=>null,'last_error'=>$error,'updated_at'=>new UTCDateTime(now())];
            if ($error === null) { $changes += ['status'=>'sent','sent_at'=>new UTCDateTime(now())]; }
            else { $changes['next_attempt_at'] = new UTCDateTime(now()->addSeconds(min(3600,30*(2**min(7,$row['attempts']))))); }
            $saved = $outbox->updateOne(['_id'=>$row['_id'],'lease_token'=>$claim],['$set'=>$changes]);
            ++$result[$saved->getModifiedCount() && $error === null ? 'sent' : 'failed'];
        }

        return $result;
    }

    private function sources(): \Generator
    {
        foreach ([ReporteTransparencia::class, Encuesta::class, Eleccion::class] as $model) {
            foreach ($model::whereIn('estado', ['publicado','retirado','publicada','cerrada'])->orderBy('_id')->cursor() as $row) { yield $row; }
        }
    }

    private function report(ReporteTransparencia $r): array
    {
        if (! $this->id((string)$r->id) || ! $this->id($r->organizacion_id) || $r->version !== 1
            || ! $r->generado_en || ! $r->publicado_en || $r->generado_en->gt($r->publicado_en) || $r->publicado_en->isFuture()
            || ! $this->day($r->fecha_inicio) || ! $this->day($r->fecha_fin) || $r->fecha_inicio > $r->fecha_fin) { return []; }
        $metrics = [];
        foreach ($r->metricas ?? [] as $m) {
            $key = $m['clave'] ?? null;
            if (! in_array($key, self::METRICS, true)) { continue; }
            if (array_key_exists($key,$metrics) || ! is_int($m['valor'] ?? null) || $m['valor'] < 0
                || ($m['unidad'] ?? null) !== (str_ends_with($key,'centavos') ? 'centavos_mxn' : 'cantidad')) { return []; }
            $metrics[$key] = $m['valor'];
        }
        if ($metrics === []) { return []; }
        $payload = ['organization_id'=>$r->organizacion_id,'report_id'=>(string)$r->id,'version'=>1,
            'period'=>['start'=>$r->fecha_inicio,'end'=>$r->fecha_fin], 'generated_at'=>$r->generado_en->toIso8601String(), 'metrics'=>$metrics];
        $events = [$this->envelope('report.published',(string)$r->id.':1:published',(string)$r->id,$r->publicado_en,$payload)];
        if ($r->estado === 'retirado') {
            if (! $r->retirado_en || $r->retirado_en->lt($r->publicado_en) || $r->retirado_en->isFuture()) { return []; }
            $events[] = $this->envelope('report.withdrawn',(string)$r->id.':1:withdrawn',(string)$r->id,$r->retirado_en,$payload);
        }

        return $events;
    }

    private function consultation(\App\Models\ConsultaComunidad $q): array
    {
        if ($q->fase() !== 'cerrada' || ! $this->id((string)$q->id) || ! $this->id($q->organizacion_id)
            || ! is_int($q->getRawOriginal('revision')) || $q->revision < 1 || ! $q->publicado_en
            || ! is_int($q->getRawOriginal('total_padron')) || $q->total_padron < 0 || ! is_array($q->padron)
            || count($q->padron) !== $q->total_padron) { return []; }
        $closed = $q->estado === 'cerrada' ? $q->cerrado_en : $q->fecha_fin;
        if (! $closed || $closed->isFuture() || $closed->lt($q->publicado_en)) { return []; }
        $model = $q instanceof Encuesta ? RespuestaEncuesta::class : Voto::class;
        $count = $model::where('consulta_id',(string)$q->id)->where('organizacion_id',$q->organizacion_id)->where('creado_en','<=',$closed)->count();
        if ($count > $q->total_padron) { return []; }
        $payload = ['organization_id'=>$q->organizacion_id,'consultation_id'=>(string)$q->id,'revision'=>$q->revision,
            'eligible_count'=>$q->total_padron,'participation_count'=>$count,'closed_at'=>$closed->toIso8601String()];

        return [$this->envelope($q instanceof Encuesta ? 'survey.closed' : 'election.closed',
            (string)$q->id.':'.$q->revision,(string)$q->id,$closed,$payload)];
    }

    private function envelope(string $type, string $key, string $id, $date, array $payload): array
    {
        return ['event_id'=>Uuid::uuid5(Uuid::NAMESPACE_URL,'campus:community:analytics:'.$type.':'.$key)->toString(),
            'event_name'=>'community.'.$type.'.v1','source_domain'=>'community','aggregate_id'=>$id,
            'occurred_at'=>$date->toIso8601String(),'payload'=>$payload];
    }

    private function id(mixed $id): bool { return is_string($id) && (bool)preg_match('/\A[0-9a-f]{24}\z/',$id); }
    private function day(mixed $day): bool
    {
        if (!is_string($day) || !preg_match('/\A\d{4}-\d{2}-\d{2}\z/',$day)) { return false; }
        try { return CarbonImmutable::createFromFormat('!Y-m-d',$day)->format('Y-m-d') === $day; } catch (\Exception) { return false; }
    }
}
