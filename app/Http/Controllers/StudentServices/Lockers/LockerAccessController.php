<?php

namespace App\Http\Controllers\StudentServices\Lockers;

use App\Http\Controllers\Controller;
use App\Http\Requests\StudentServices\Lockers\ValidateLockerAccessRequest;
use App\Services\StudentServices\Lockers\LockerAssignmentService;
use Inertia\Inertia;
use Inertia\Response;

class LockerAccessController extends Controller
{
    public function __construct(
        private readonly LockerAssignmentService $assignments
    ) {}

    public function index(): Response
    {
        return Inertia::render(
            'student-services/lockers/Access',
            [
                'result' => null,
            ]
        );
    }

    public function check(
        ValidateLockerAccessRequest $request
    ): Response {
        $result =
            $this->assignments
                ->validateAccess(
                    $request
                        ->validated()[
                    'code'
                    ]
                );

        return Inertia::render(
            'student-services/lockers/Access',
            [
                'result' => $result,
            ]
        );
    }
}
