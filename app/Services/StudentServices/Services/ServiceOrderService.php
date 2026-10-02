<?php

namespace App\Services\StudentServices\Services;

use App\Models\StudentServices\Services\PrintJob;
use App\Models\StudentServices\Services\ServiceOrder;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use MongoDB\BSON\ObjectId;
use RuntimeException;
use Throwable;

class ServiceOrderService
{
    private const SERVICE_TYPES = [
        'printing',
        'copy',
        'scanning',
        'binding',
    ];

    private const COLOR_MODES = [
        'bw',
        'color',
    ];

    private const SIDES = [
        'single',
        'double',
    ];

    public function createOrder(
        string $studentId,
        string $serviceType,
        int $quantity,
        ?string $colorMode = null,
        ?string $paperSize = null,
        ?string $sides = null,
        ?string $observations = null,
        ?UploadedFile $file = null
    ): ServiceOrder {
        $studentId = trim($studentId);

        if ($studentId === '') {
            throw new InvalidArgumentException(
                'El estudiante es obligatorio.'
            );
        }

        if (
            !in_array(
                $serviceType,
                self::SERVICE_TYPES,
                true
            )
        ) {
            throw new InvalidArgumentException(
                'El tipo de servicio no es válido.'
            );
        }

        if ($quantity < 1) {
            throw new InvalidArgumentException(
                'La cantidad debe ser mayor a cero.'
            );
        }

        if (
            in_array(
                $serviceType,
                [
                    'printing',
                    'copy',
                    'scanning',
                ],
                true
            )
        ) {
            if (
                $colorMode === null ||
                !in_array(
                    $colorMode,
                    self::COLOR_MODES,
                    true
                )
            ) {
                throw new InvalidArgumentException(
                    'El modo de color no es válido.'
                );
            }

            if (
                $paperSize === null ||
                trim($paperSize) === ''
            ) {
                throw new InvalidArgumentException(
                    'El tamaño de papel es obligatorio.'
                );
            }
        } else {
            $colorMode = null;
            $paperSize = null;
        }

        if (
            in_array(
                $serviceType,
                [
                    'printing',
                    'copy',
                ],
                true
            )
        ) {
            if (
                $sides === null ||
                !in_array(
                    $sides,
                    self::SIDES,
                    true
                )
            ) {
                throw new InvalidArgumentException(
                    'La configuración de caras no es válida.'
                );
            }
        } else {
            $sides = null;
        }

        if (
            $serviceType === 'printing' &&
            $file === null
        ) {
            throw new InvalidArgumentException(
                'Debes adjuntar el archivo que deseas imprimir.'
            );
        }

        $quotedAmountCents =
            $this->calculateQuote(
                $serviceType,
                $quantity,
                $colorMode,
                $sides
            );

        $folio =
            $this->generateFolio();

        $order = null;
        $storedFilePath = null;

        try {
            if ($file !== null) {
                $storedFilePath =
                    $file->store(
                        'student-services/service-orders',
                        'local'
                    );
            }

            $order =
                ServiceOrder::create([
                    'folio' =>
                        $folio,

                    'student_id' =>
                        $studentId,

                    'service_type' =>
                        $serviceType,

                    'quantity' =>
                        $quantity,

                    'observations' =>
                        $observations,

                    'quoted_amount_cents' =>
                        $quotedAmountCents,

                    'payment_status' =>
                        'pending',

                    'payment_reference_id' =>
                        null,

                    'status' =>
                        'awaiting_payment',

                    'requested_at' =>
                        now(),

                    'paid_at' =>
                        null,

                    'processing_at' =>
                        null,

                    'ready_at' =>
                        null,

                    'delivered_at' =>
                        null,

                    'cancelled_at' =>
                        null,
                ]);

            if (
                in_array(
                    $serviceType,
                    [
                        'printing',
                        'copy',
                        'scanning',
                    ],
                    true
                )
            ) {
                PrintJob::create([
                    'service_order_id' =>
                        $order->id,

                    'job_type' =>
                        $serviceType,

                    'file_name' =>
                        $file?->getClientOriginalName(),

                    'file_path' =>
                        $storedFilePath,

                    'file_mime' =>
                        $file?->getClientMimeType(),

                    'color_mode' =>
                        $colorMode,

                    'paper_size' =>
                        $paperSize,

                    'sides' =>
                        $sides,
                ]);
            }

            return $order->fresh();
        } catch (Throwable $exception) {
            if ($order !== null) {
                PrintJob::query()
                    ->where(
                        'service_order_id',
                        new ObjectId(
                            (string) $order->id
                        )
                    )
                    ->delete();

                $order->delete();
            }

            if (
                $storedFilePath !== null &&
                \Storage::disk(
                    'local'
                )->exists(
                    $storedFilePath
                )
            ) {
                \Storage::disk(
                    'local'
                )->delete(
                    $storedFilePath
                );
            }

            throw $exception;
        }
    }

    public function cancelOrder(
        ServiceOrder $order
    ): ServiceOrder {
        if (
            !in_array(
                $order->status,
                [
                    'pending',
                    'quoted',
                    'awaiting_payment',
                ],
                true
            )
        ) {
            throw new RuntimeException(
                'Esta solicitud ya no puede cancelarse.'
            );
        }

        $order->update([
            'status' =>
                'cancelled',

            'cancelled_at' =>
                now(),
        ]);

        return $order->fresh();
    }

    public function markAsPaid(
        ServiceOrder $order,
        string $paymentReferenceId
    ): ServiceOrder {
        if (
            $order->status !==
            'awaiting_payment' ||
            $order->payment_status !==
            'pending'
        ) {
            throw new RuntimeException(
                'Esta solicitud no está pendiente de pago.'
            );
        }

        $paymentReferenceId =
            trim($paymentReferenceId);

        if (
            $paymentReferenceId === ''
        ) {
            throw new InvalidArgumentException(
                'La referencia de pago es obligatoria.'
            );
        }

        $order->update([
            'payment_status' =>
                'paid',

            'payment_reference_id' =>
                $paymentReferenceId,

            'paid_at' =>
                now(),

            'status' =>
                'processing',

            'processing_at' =>
                now(),
        ]);

        return $order->fresh();
    }

    public function markReady(
        ServiceOrder $order
    ): ServiceOrder {
        if (
            $order->status !==
            'processing'
        ) {
            throw new RuntimeException(
                'Solo una solicitud en proceso puede marcarse como lista.'
            );
        }

        $order->update([
            'status' =>
                'ready',

            'ready_at' =>
                now(),
        ]);

        return $order->fresh();
    }

    public function markDelivered(
        ServiceOrder $order
    ): ServiceOrder {
        if (
            $order->status !==
            'ready'
        ) {
            throw new RuntimeException(
                'Solo una solicitud lista puede entregarse.'
            );
        }

        $order->update([
            'status' =>
                'delivered',

            'delivered_at' =>
                now(),
        ]);

        return $order->fresh();
    }

    public function calculateQuote(
        string $serviceType,
        int $quantity,
        ?string $colorMode = null,
        ?string $sides = null
    ): int {
        $amountPesos = 0.0;

        switch ($serviceType) {
            case 'printing':
                $amountPesos =
                    $quantity *
                    (
                    $colorMode === 'color'
                        ? 5
                        : 2
                    );
                break;

            case 'copy':
                $amountPesos =
                    $quantity *
                    (
                    $colorMode === 'color'
                        ? 4
                        : 1.5
                    );
                break;

            case 'scanning':
                $amountPesos =
                    $quantity * 3;
                break;

            case 'binding':
                $amountPesos =
                    $quantity * 45;
                break;

            default:
                throw new InvalidArgumentException(
                    'El tipo de servicio no es válido.'
                );
        }

        if (
            $sides === 'double' &&
            in_array(
                $serviceType,
                [
                    'printing',
                    'copy',
                ],
                true
            )
        ) {
            $amountPesos *= 0.9;
        }

        return (int) round(
            $amountPesos * 100
        );
    }

    private function generateFolio(): string
    {
        do {
            $folio =
                'SER-' .
                now()->format('Y') .
                '-' .
                strtoupper(
                    substr(
                        bin2hex(
                            random_bytes(4)
                        ),
                        0,
                        6
                    )
                );

            $exists =
                ServiceOrder::query()
                    ->where(
                        'folio',
                        $folio
                    )
                    ->exists();
        } while ($exists);

        return $folio;
    }
}
