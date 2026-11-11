<?php

namespace Tests\Feature;

use App\Models\{ReporteTransparencia,Encuesta,Eleccion,RespuestaEncuesta,Voto};
use App\Services\CommunityAnalyticsDelivery;
use Illuminate\Support\Facades\{DB,Http};
use Tests\TestCase;

class CommunityAnalyticsDeliveryTest extends TestCase
{
    private string $org;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Carbon\Carbon::parse('2026-11-11T18:00:00Z'));
        $this->org = (string) new \MongoDB\BSON\ObjectId;
        config(['platform.community_analytics.enabled' => true, 'platform.community_analytics.since' => '2026-11-01T00:00:00Z',
            'platform.delivery.url' => 'http://app', 'platform.ingest.secrets.community' => 'analytics-fixture-secret-20261111']);
        Http::preventStrayRequests();
    }

    private function report(array $extra = []): ReporteTransparencia
    {
        return ReporteTransparencia::create(['organizacion_id'=>$this->org,'organizacion_nombre'=>'Nombre privado',
            'titulo'=>'Texto privado','descripcion'=>'Expediente privado','version'=>1,'estado'=>'publicado',
            'fecha_inicio'=>'2026-11-01','fecha_fin'=>'2026-11-10','generado_en'=>'2026-11-10T18:00:00Z',
            'publicado_en'=>'2026-11-10T19:00:00Z',
            'metricas'=>[['clave'=>'asistencias','valor'=>2,'unidad'=>'cantidad','etiqueta'=>'Privada','criterio'=>'No publicar']],...$extra]);
    }

    public function test_published_report_is_frozen_private_signed_and_recovered_after_a_lost_response(): void
    {
        $report=$this->report();$bodies=[];
        Http::fake(['http://app/api/v1/plataforma/eventos'=>function($r)use(&$bodies){
            $bodies[]=$r->body();
            if(count($bodies)===1)return Http::failedConnection();
            return Http::response(['estado'=>'duplicado','event_id'=>$r['event_id']],200);
        }]);
        $s=app(CommunityAnalyticsDelivery::class);
        $first=$s->deliver();$this->assertSame(1,$first['failed']);
        $report->update(['titulo'=>'Otro nombre privado']);
        $this->travel(61)->seconds();
        $second=$s->deliver();$this->assertSame(1,$second['sent']);
        $this->assertSame($bodies[0],$bodies[1]);
        $this->assertStringNotContainsString('privad',strtolower($bodies[0]));
        $data=json_decode($bodies[0],true);
        $this->assertSame(['asistencias'=>2],$data['payload']['metrics']);
        $this->assertSame('community.report.published.v1',$data['event_name']);
        $this->assertSame((string)$report->id,$data['aggregate_id']);
        Http::assertSent(function($r){$time=$r->header('X-Platform-Timestamp')[0];return hash_equals($r->header('X-Platform-Signature')[0],
            hash_hmac('sha256',$time.'|POST|/api/v1/plataforma/eventos|'.hash('sha256',$r->body()),config('platform.ingest.secrets.community')));});
        $this->assertSame(0,$s->deliver()['sent']);Http::assertSentCount(2);
    }

    public function test_withdrawal_is_a_separate_event_and_preserves_the_original_snapshot(): void
    {
        $report=$this->report();$types=[];
        Http::fake(['http://app/api/v1/plataforma/eventos'=>function($r)use(&$types){$types[]=$r['event_name'];return Http::response(['estado'=>'aceptado','event_id'=>$r['event_id']],202);}]);
        $s=app(CommunityAnalyticsDelivery::class);$this->assertSame(1,$s->deliver()['sent']);
        $report->update(['estado'=>'retirado','retirado_en'=>now(),'motivo_retiro'=>'Motivo reservado']);
        $this->assertSame(1,$s->deliver()['sent']);
        $this->assertSame(['community.report.published.v1','community.report.withdrawn.v1'],$types);
        $this->assertSame(0,$s->deliver()['sent']);
    }

    public function test_only_closed_surveys_and_elections_export_participation_totals(): void
    {
        $student=(string)new \MongoDB\BSON\ObjectId;$sent=[];
        foreach([Encuesta::class=>RespuestaEncuesta::class,Eleccion::class=>Voto::class]as$model=>$response){
            $q=$model::create(['organizacion_id'=>$this->org,'titulo'=>'Pregunta privada','estado'=>'publicada','revision'=>1,
                'fecha_inicio'=>'2026-11-02T00:00:00Z','fecha_fin'=>'2026-11-10T00:00:00Z','publicado_en'=>'2026-11-01T18:00:00Z',
                'total_padron'=>1,'padron'=>[$student],'preguntas'=>[['titulo'=>'Opinión privada']]]);
            $this->travelTo(\Carbon\Carbon::parse('2026-11-03T00:00:00Z'));
            $response::create(['organizacion_id'=>$this->org,'consulta_id'=>(string)$q->id,'usuario_id'=>$student,'respuestas'=>[['opcion_id'=>'Privado']]]);
            $this->travelTo(\Carbon\Carbon::parse('2026-11-11T18:00:00Z'));
        }
        Http::fake(['http://app/api/v1/plataforma/eventos'=>function($r)use(&$sent){$sent[]=$r->data();return Http::response(['estado'=>'aceptado','event_id'=>$r['event_id']],202);}]);
        $this->assertSame(2,app(CommunityAnalyticsDelivery::class)->deliver()['sent']);
        foreach($sent as$event){$this->assertSame(1,$event['payload']['eligible_count']);$this->assertSame(1,$event['payload']['participation_count']);}
        $wire=json_encode($sent);$this->assertStringNotContainsString($student,$wire);$this->assertStringNotContainsString('Privad',$wire);$this->assertStringNotContainsString('respuestas',$wire);
    }

    public function test_drafts_future_records_bad_metrics_and_outside_cutoff_do_not_emit(): void
    {
        $this->report(['estado'=>'borrador']);$this->report(['publicado_en'=>'2026-11-12T00:00:00Z']);
        $this->report(['publicado_en'=>'2026-10-31T00:00:00Z','generado_en'=>'2026-10-30T00:00:00Z']);
        $this->report(['metricas'=>[['clave'=>'asistencias','valor'=>-1,'unidad'=>'cantidad']]]);
        $this->report(['metricas'=>[['clave'=>'asistencias','valor'=>'2','unidad'=>'cantidad']]]);
        $this->assertSame(0,app(CommunityAnalyticsDelivery::class)->deliver()['sent']);Http::assertNothingSent();
    }

    public function test_malformed_receipt_stays_pending_and_disabled_worker_never_sends(): void
    {
        $this->report();Http::fake(['http://app/*'=>Http::response(['estado'=>'aceptado','event_id'=>'wrong'],202)]);
        $this->assertSame(1,app(CommunityAnalyticsDelivery::class)->deliver()['failed']);
        config(['platform.community_analytics.enabled'=>false]);$this->assertFalse(app(CommunityAnalyticsDelivery::class)->deliver()['enabled']);Http::assertSentCount(1);
    }

    public function test_missing_since_fails_before_sending(): void
    {
        $this->report();config(['platform.community_analytics.since'=>null]);
        try{app(CommunityAnalyticsDelivery::class)->deliver();$this->fail('Expected invalid cutoff');}catch(\InvalidArgumentException){}
        Http::assertNothingSent();
    }
}
