<?php

namespace App\Http\Controllers\Api\Identity;

use App\Exceptions\DirectoryFailure;
use App\Http\Controllers\Controller;
use App\Models\SecurityEvent;
use App\Services\Directory\DirectoryInput;
use App\Services\Directory\IdentityDirectoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class IdentityDirectoryController extends Controller
{
    public function resolve(Request $request, IdentityDirectoryService $directory): JsonResponse
    {
        return $this->deliver($request, $directory->resolve($request->attributes->get('directory_input')));
    }

    public function batch(Request $request, IdentityDirectoryService $directory): JsonResponse
    {
        return $this->deliver($request, $directory->batch($request->attributes->get('directory_input')));
    }

    private function deliver(Request $request, array $data): JsonResponse
    {
        $input = $request->attributes->get('directory_input');
        $partial = collect($data['items'] ?? [])->contains(fn ($item) => in_array($item['result'], ['invalid_id', 'unavailable'], true));
        $response = response()->json(['data' => $data, 'meta' => [
            'request_id' => $request->attributes->get('directory_request_id'), 'api_version' => 'v1',
            ...($input['operation'] === 'batch' ? ['partial' => $partial] : []),
        ]]);
        if (strlen($response->getContent()) > DirectoryInput::MAX_RESPONSE_BYTES) { throw new DirectoryFailure(503, 'directory_response_unavailable'); }
        try {
            $audit = ['type' => 'identity_directory_consulted', 'severity' => 'info', 'metadata' => [
                'client_id' => $request->attributes->get('oauth_client_id'), 'operation' => $input['operation'],
                'purpose' => $input['purpose'], 'context_type' => $input['context']['type'],
                'reference_count' => $input['reference_count'], 'result' => $partial ? 'partial' : ($data['result'] ?? 'completed'),
                'request_id' => $request->attributes->get('directory_request_id'),
            ]];
            $event = SecurityEvent::log($audit);
            // create() can return an unsaved model when saving/creating returns false.
            // A generated ID alone is not proof of persistence.
            if (! $event->exists || ! $event->wasRecentlyCreated || $event->getKey() === null) {
                throw new DirectoryFailure(503, 'directory_audit_unavailable');
            }
            $stored = SecurityEvent::query()->whereKey($event->getKey())
                ->first(['_id', 'type', 'severity', 'metadata']);
            if (! $stored || (string) $stored->getKey() !== (string) $event->getKey()
                || $stored->type !== $audit['type'] || $stored->severity !== $audit['severity']
                || $stored->metadata !== $audit['metadata']) {
                throw new DirectoryFailure(503, 'directory_audit_unavailable');
            }
        } catch (Throwable) {
            throw new DirectoryFailure(503, 'directory_audit_unavailable');
        }

        return $response; // Mandatory audit has succeeded; only now release protected data.
    }
}
