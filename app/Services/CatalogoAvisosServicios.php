<?php

namespace App\Services;

class CatalogoAvisosServicios
{
    public const TIPOS = [
        'prestamo_por_vencer' => ['mis_prestamos', false],
        'prestamo_vencido' => ['mis_prestamos', true],
        'reserva_libro_disponible' => ['mis_reservas_libros', true],
        'multa_generada' => ['mis_multas', true],
        'locker_por_vencer' => ['mi_locker', false],
        'lista_espera_promovida' => ['mis_reservas', true],
        'recordatorio_reserva' => ['mis_reservas', false],
        'no_show_registrado' => ['mis_reservas', true],
        'renta_por_devolver' => ['mis_rentas', false],
        'orden_lista' => ['mis_ordenes', false],
        'ticket_actualizado' => ['mi_ticket', false],
    ];

    public const DESTINOS = [
        'mis_prestamos' => ['/servicios-estudiante/biblioteca/prestamos', 'Mis préstamos'],
        'mis_reservas_libros' => ['/servicios-estudiante/biblioteca/reservas', 'Mis reservas de libros'],
        'mis_multas' => ['/servicios-estudiante/biblioteca/multas', 'Mis multas'],
        'mi_locker' => ['/servicios-estudiante/lockers/asignaciones', 'Mi locker'],
        'mis_reservas' => ['/servicios-estudiante/calendarios-cupos', 'Mis reservas'],
        'mis_rentas' => ['/servicios-estudiante/renta-equipos', 'Mis rentas'],
        'mis_ordenes' => ['/servicios-estudiante/servicios-impresiones', 'Mis órdenes'],
        'mi_ticket' => ['/servicios-estudiante/soporte', 'Mi ticket'],
    ];

    public function accion(string $tipo, array $accion): array
    {
        [$destino, $obligatoria] = self::TIPOS[$tipo];
        if ($accion['destino'] !== $destino) {
            throw new \App\Exceptions\AvisoApiException(422, 'destino_invalido', 'El destino no corresponde al tipo de aviso.');
        }
        [$url, $texto] = self::DESTINOS[$destino];

        return ['url' => $url.'?registro='.rawurlencode($accion['id']), 'texto' => $texto, 'obligatoria' => $obligatoria];
    }
}
