<?php
namespace Tests\Unit;
use App\Services\IdentityPlatformEnvelope;
use Illuminate\Container\Container;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;
final class IdentityPlatformEnvelopeTest extends TestCase
{
    private $previous;
    protected function setUp():void {$this->previous=Container::getInstance();$c=new Container;Container::setInstance($c);$c->instance('config',new Repository(['app'=>['env'=>'testing']]));}
    protected function tearDown():void {Container::setInstance($this->previous);}
    private function event(string $name='student.profile.changed.v1'):object
    {return (object)['event_id'=>'11111111-2222-4333-8444-555555555555','event_name'=>$name,'aggregate_id'=>'student-1','occurred_at'=>new \MongoDB\BSON\UTCDateTime(1794412800000),'payload'=>new \MongoDB\Model\BSONDocument(['student_id'=>'student-1','operation'=>'updated','email'=>'private@example.invalid','reason'=>'private text'])];}
    public function test_maps_namespace_and_keeps_id_without_personal_fields():void
    {$wire=IdentityPlatformEnvelope::prepare($this->event(),'http://127.0.0.1/api/v1/plataforma/eventos',str_repeat('k',32),1794412800);$v=json_decode($wire['body'],true);self::assertSame('identity.student.profile.changed.v1',$v['event_name']);self::assertSame($this->event()->event_id,$v['event_id']);self::assertSame(['student_id'=>'student-1','operation'=>'updated'],$v['payload']);}
    public function test_signature_matches_receiver_exact_bytes():void
    {$wire=IdentityPlatformEnvelope::prepare($this->event(),'http://127.0.0.1/api/v1/plataforma/eventos',str_repeat('k',32),1794412800);self::assertSame(hash_hmac('sha256','1794412800|POST|/api/v1/plataforma/eventos|'.hash('sha256',$wire['body']),str_repeat('k',32)),$wire['headers']['X-Platform-Signature']);self::assertSame('identity',$wire['headers']['X-Platform-Domain']);}
    public function test_retries_keep_body_and_event_id_stable():void
    {$a=IdentityPlatformEnvelope::prepare($this->event(),'http://localhost/api/v1/plataforma/eventos',str_repeat('k',32),1794412800);$b=IdentityPlatformEnvelope::prepare($this->event(),'http://localhost/api/v1/plataforma/eventos',str_repeat('k',32),1794412801);self::assertSame($a['body'],$b['body']);self::assertNotSame($a['headers']['X-Platform-Signature'],$b['headers']['X-Platform-Signature']);}
    public function test_unknown_event_denied():void {$this->expectException(\InvalidArgumentException::class);IdentityPlatformEnvelope::prepare($this->event('other.event.v1'),'http://localhost/api/v1/plataforma/eventos',str_repeat('k',32));}
    public function test_remote_plain_http_denied():void {$this->expectException(\InvalidArgumentException::class);IdentityPlatformEnvelope::prepare($this->event(),'http://remote.invalid/api/v1/plataforma/eventos',str_repeat('k',32));}
    public function test_query_or_wrong_endpoint_denied():void {$this->expectException(\InvalidArgumentException::class);IdentityPlatformEnvelope::prepare($this->event(),'https://remote.invalid/api/v1/plataforma/eventos?x=1',str_repeat('k',32));}
    public function test_missing_secret_denied():void {$this->expectException(\InvalidArgumentException::class);IdentityPlatformEnvelope::prepare($this->event(),'http://localhost/api/v1/plataforma/eventos','');}
}
