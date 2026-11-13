<?php
namespace App\Services\Directory;

use App\Exceptions\DirectoryFailure;
use App\Models\{AcademicProgram,Campus,StudentProfile,SecurityEvent};
use App\Services\{RecentTwoFactor,InstitutionalAuthorizationService};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/** In-process human Community contract, NOT an OAuth token-to-human bridge. */
class AcademicAudienceService
{
    public function __construct(protected RecentTwoFactor $session,protected InstitutionalAuthorizationService $authority,
        protected DirectoryQuota $quota,protected IdentityDirectoryService $directory) {}

    public function select(Request $request,string $client,array $data): array
    {
        $input=AcademicAudienceInput::parse($data);
        $profile=AcademicAudienceInput::authorize($client,$input['academic_program_id'],config('identity_directory.academic_clients',[]));
        try {
            if ($request!==request() || !$this->session->hasActiveSession($request)
                || !$this->authority->allows($request->user('web'),'academic.program.coordinate','academic_program',$input['academic_program_id'])) throw new DirectoryFailure(403,'directory_access_denied');
            if (config('identity_directory.enrollment_integrity_verified')!==true) throw new DirectoryFailure(503,'directory_integrity_not_attested');
            $program=AcademicProgram::whereKey($input['academic_program_id'])->where('is_active',true)->first();
            $campus=$program ? Campus::whereKey($program->campus_id)->where('is_active',true)->first() : null;
            if (!$campus) throw new DirectoryFailure(403,'directory_access_denied');
            $binding=['client'=>$client,'actor'=>(string)$request->user('web')->getKey(),'program'=>$input['academic_program_id'],
                'semester'=>$input['semester'],'limit'=>$input['limit']];
            $after=null;
            if ($input['cursor']!==null) {
                try { $cursor=json_decode(Crypt::decryptString($input['cursor']),true,512,JSON_THROW_ON_ERROR); }
                catch(\Illuminate\Contracts\Encryption\DecryptException|\JsonException) { throw new DirectoryFailure(422,'directory_invalid_cursor'); }
                if (($cursor['binding']??null)!==$binding || !is_int($cursor['expires']??null) || $cursor['expires']<=now()->timestamp
                    || !is_string($cursor['after']??null) || !preg_match('/\A[0-9a-f]{24}\z/',$cursor['after'])) throw new DirectoryFailure(422,'directory_invalid_cursor');
                $after=$cursor['after'];
            }
            // Shared existing quota store. Charge the maximum page size before reading any identities.
            $this->quota->consume($client,['operation'=>'batch','reference_count'=>$input['limit']],$profile);
            $query=StudentProfile::where('academic_program_id',(string)$program->getKey())
                ->where('campus_id',(string)$campus->getKey())->where('academic_status','active');
            if ($input['semester']!==null) $query->where('current_semester',$input['semester']);
            if ($after!==null) $query->where('_id','>',new \MongoDB\BSON\ObjectId($after));
            $rows=$query->orderBy('_id')->limit($input['limit']+1)->get(['_id','user_id']);
            $hasMore=$rows->count()>$input['limit'];$page=$rows->take($input['limit']);
            $ids=$page->pluck('user_id')->all();
            if (count(array_unique($ids))!==count($ids)) throw new DirectoryFailure(503,'directory_inconsistent_reference');
            $result=$ids===[]?['items'=>[]]:$this->directory->batch(['user_ids'=>$ids,'projection'=>['identity','account_condition'],'include_enrollment'=>false]);
            $items=array_values(array_filter($result['items'],fn($item)=>($item['result']??null)==='found' && ($item['account_condition']['usable']??false)===true));
            $next=$hasMore ? Crypt::encryptString(json_encode(['binding'=>$binding,'after'=>(string)$page->last()->getKey(),'expires'=>now()->addMinutes(5)->timestamp],JSON_THROW_ON_ERROR)) : null;
            $event=SecurityEvent::log(['user_id'=>$binding['actor'],'type'=>'identity_academic_audience',
                'metadata'=>['client'=>$client,'program_id'=>$binding['program'],'count'=>count($items)]]);
            if (!$event->exists || !$event->wasRecentlyCreated) throw new DirectoryFailure(503,'directory_audit_unavailable');
            return ['api_version'=>'v1','items'=>$items,'next_cursor'=>$next,'references'=>[
                'campus_id'=>(string)$campus->getKey(),'academic_program_id'=>(string)$program->getKey()],
                'consistency'=>'live-page-not-snapshot'];
        } catch(DirectoryFailure $e) { throw $e; }
        catch(\MongoDB\Driver\Exception\ConnectionException) { throw new DirectoryFailure(503,'directory_unavailable'); }
    }
}
