<?php

namespace App\Actions\Students;

use App\Enums\PreferredContactChannel;
use App\Enums\StudentStatus;
use App\Models\Campus;
use App\Models\StudentProfile;
use App\Models\User;
use App\Support\ExecutesMongoAtomically;
use App\Support\StudentIdentityInput;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use MongoDB\BSON\Regex;
use SplFileObject;

class ImportStudents
{
    use ExecutesMongoAtomically;

    private const HEADERS = ['matricula', 'nombre', 'correo_institucional', 'campus', 'carrera', 'semestre', 'grupo', 'estatus', 'correo_personal', 'telefono', 'canal_preferido'];

    public function __construct(private readonly UpsertStudentProfile $upsert) {}

    public function execute(UploadedFile $file, User $actor): array
    {
        $rows = $this->readRows($file);
        $campuses = Campus::with('academicPrograms')->get();
        $prepared = [];
        $errors = [];
        $seenEnrollments = [];
        $seenEmails = [];

        foreach ($rows as ['line' => $line, 'values' => $row]) {
            $campus = $campuses->first(fn (Campus $item) =>
                strcasecmp($item->code, $row['campus']) === 0 || strcasecmp($item->name, $row['campus']) === 0
            );
            $program = $campus?->academicPrograms->first(fn ($item) =>
                strcasecmp($item->code, $row['carrera']) === 0 || strcasecmp($item->name, $row['carrera']) === 0
            );
            $data = StudentIdentityInput::normalize([
                'name' => $row['nombre'],
                'email' => $row['correo_institucional'],
                'enrollment_number' => $row['matricula'],
                'campus_id' => $campus?->getKey() ? (string) $campus->getKey() : null,
                'academic_program_id' => $program?->getKey() ? (string) $program->getKey() : null,
                'current_semester' => $row['semestre'],
                'group_name' => $row['grupo'] ?: null,
                'academic_status' => Str::lower($row['estatus']),
                'personal_email' => $row['correo_personal'] ?: null,
                'phone' => $row['telefono'] ?: null,
                'preferred_contact_channel' => Str::lower($row['canal_preferido']),
                'locale' => 'es-MX',
                'status_reason' => 'Importación CSV',
            ]);

            $enrollment = $data['enrollment_number'];
            $email = $data['email'];
            $duplicateEnrollment = isset($seenEnrollments[$enrollment]);
            $duplicateEmail = isset($seenEmails[$email]);
            $seenEnrollments[$enrollment] = true;
            $seenEmails[$email] = true;

            $profile = $enrollment !== '' ? StudentProfile::where('enrollment_number', 'regex', $this->canonicalMatch($enrollment))->first() : null;
            $student = $profile?->user;
            $emailOwner = $email !== '' ? User::where('email', 'regex', $this->canonicalMatch($email))->first() : null;

            $validator = Validator::make($data, [
                'name' => ['required', 'string', 'max:120'],
                'email' => ['required', 'email', 'max:255'],
                'enrollment_number' => ['required', 'string', 'max:30', 'regex:/^[A-Za-z0-9-]+$/'],
                'campus_id' => ['required', 'string'],
                'academic_program_id' => ['required', 'string'],
                'current_semester' => ['required', 'integer', 'between:1,20'],
                'group_name' => ['nullable', 'string', 'max:30'],
                'academic_status' => ['required', Rule::enum(StudentStatus::class)],
                'personal_email' => ['nullable', 'email', 'max:255', 'different:email'],
                'phone' => ['nullable', 'string', 'max:25', 'regex:/^[0-9+() .-]+$/'],
                'preferred_contact_channel' => ['required', Rule::enum(PreferredContactChannel::class)],
                'locale' => ['required', Rule::in(['es-MX', 'en-US'])],
            ]);
            $validator->after(function ($validator) use ($data, $duplicateEnrollment, $duplicateEmail, $campus, $program, $profile, $student, $emailOwner): void {
                if ($duplicateEnrollment) {
                    $validator->errors()->add('enrollment_number', 'La matrícula está duplicada dentro del archivo.');
                }
                if ($duplicateEmail) {
                    $validator->errors()->add('email', 'El correo institucional está duplicado dentro del archivo.');
                }
                if (! $campus) {
                    $validator->errors()->add('campus_id', 'El campus no existe.');
                } elseif (! $program) {
                    $validator->errors()->add('academic_program_id', 'La carrera no pertenece al campus seleccionado.');
                }
                if ($profile && ! $student) {
                    $validator->errors()->add('enrollment_number', 'La matrícula no tiene un usuario asociado válido.');
                }
                if ($emailOwner && (! $student || (string) $emailOwner->getKey() !== (string) $student->getKey())) {
                    $validator->errors()->add('email', 'El correo institucional pertenece a otra cuenta.');
                }
                if ($data['preferred_contact_channel'] === PreferredContactChannel::PersonalEmail->value && empty($data['personal_email'])) {
                    $validator->errors()->add('personal_email', 'Captura el correo personal seleccionado como canal preferido.');
                }
                if ($data['preferred_contact_channel'] === PreferredContactChannel::Phone->value && empty($data['phone'])) {
                    $validator->errors()->add('phone', 'Captura el teléfono seleccionado como canal preferido.');
                }
            });

            if ($validator->fails()) {
                foreach ($validator->errors()->messages() as $field => $messages) {
                    foreach ($messages as $message) {
                        $errors[] = "Fila {$line}, {$field}: {$message}";
                    }
                }
            } else {
                $prepared[] = [$data, $student];
            }
        }

        if ($errors) {
            throw ValidationException::withMessages(['file' => $errors]);
        }

        return $this->mongoTransaction(function () use ($prepared, $actor): array {
            $result = ['created' => 0, 'updated' => 0];
            foreach ($prepared as [$data, $existing]) {
                $this->upsert->execute($data, $existing, $actor);
                $result[$existing ? 'updated' : 'created']++;
            }

            return $result;
        });
    }

    private function canonicalMatch(string $value): Regex
    {
        return new Regex('^\\s*'.preg_quote($value, '/').'\\s*$', 'i');
    }

    private function readRows(UploadedFile $file): array
    {
        $csv = new SplFileObject($file->getRealPath());
        $csv->setFlags(SplFileObject::READ_CSV | SplFileObject::SKIP_EMPTY | SplFileObject::DROP_NEW_LINE);
        $headers = array_map(fn ($header) => Str::lower(trim((string) $header, " \t\n\r\0\x0B\xEF\xBB\xBF")), $csv->fgetcsv());
        if ($headers !== self::HEADERS) {
            throw ValidationException::withMessages(['file' => 'Los encabezados no coinciden con la plantilla.']);
        }

        $rows = [];
        while (! $csv->eof()) {
            $line = $csv->key() + 2;
            $values = $csv->fgetcsv();
            if (! is_array($values) || ($values[0] ?? null) === null) {
                continue;
            }
            if (count($values) !== count($headers)) {
                throw ValidationException::withMessages(['file' => "Fila {$line}: número de columnas incorrecto."]);
            }
            $rows[] = ['line' => $line, 'values' => array_combine($headers, array_map(fn ($value) => trim((string) $value), $values))];
        }
        if (! $rows) {
            throw ValidationException::withMessages(['file' => 'El archivo no contiene estudiantes.']);
        }

        return $rows;
    }
}
