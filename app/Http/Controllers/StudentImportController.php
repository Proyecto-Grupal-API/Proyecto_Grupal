<?php

namespace App\Http\Controllers;

use App\Actions\Students\ImportStudents;
use App\Http\Requests\ImportStudentsRequest;

class StudentImportController extends Controller
{
    public function store(ImportStudentsRequest $request, ImportStudents $import)
    {
        $result = $import->execute($request->file('file'), $request->user());
        return back()->with('success', "Importación completada: {$result['created']} altas y {$result['updated']} actualizaciones.");
    }
}
