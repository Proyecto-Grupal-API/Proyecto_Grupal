<?php
namespace App\Services\Directory;
use App\Exceptions\DirectoryFailure;

final class AcademicAudienceInput
{
    public static function parse(array $data): array
    {
        if (array_diff(array_keys($data),['academic_program_id','semester','limit','cursor'])
            || !is_string($data['academic_program_id']??null)
            || !preg_match('/\A[0-9a-f]{24}\z/',$data['academic_program_id'])
            || !is_int($limit=$data['limit']??50) || $limit<1 || $limit>50
            || (isset($data['semester']) && (!is_int($data['semester']) || $data['semester']<1 || $data['semester']>20))
            || (isset($data['cursor']) && (!is_string($data['cursor']) || strlen($data['cursor'])>4096))) {
            throw new DirectoryFailure(422,'directory_invalid_request');
        }
        return ['academic_program_id'=>$data['academic_program_id'],'semester'=>$data['semester']??null,
            'limit'=>$limit,'cursor'=>$data['cursor']??null];
    }
    public static function authorize(string $client,string $program,array $profiles): array
    {
        $profile=$profiles[$client]??null;
        if (!is_array($profile) || ($profile['active']??false)!==true
            || !is_int($profile['daily_reference_limit']??null) || $profile['daily_reference_limit']<1
            || !is_array($profile['academic_program_ids']??null) || !in_array($program,$profile['academic_program_ids'],true)) throw new DirectoryFailure(403,'directory_access_denied');
        return $profile;
    }
}
