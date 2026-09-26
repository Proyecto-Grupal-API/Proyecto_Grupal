<?php
namespace App\Http\Controllers;

use App\Services\IdentidadComunidadApi;
use Illuminate\Http\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\ConnectionException;
use RuntimeException;

class IdentidadComunidadController extends Controller
{
    public function __invoke(Request $request, IdentidadComunidadApi $api)
    {
        try {
            return response()->json(['data' => $api->estado((string) $request->user()->id)])
                ->header('Cache-Control', 'private, no-store');
        } catch (RequestException $e) {
            return response()->json(['message' => $e->response->status() === 404
                ? 'No tienes un perfil académico registrado en Identidad.'
                : 'No se pudo consultar Identidad. Intenta más tarde.'], $e->response->status() === 404 ? 404 : 503);
        } catch (ConnectionException|RuntimeException $e) {
            return response()->json(['message' => 'La consulta a Identidad no está disponible. Intenta más tarde.'], 503);
        }
    }
}
