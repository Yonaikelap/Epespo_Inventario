<?php

namespace App\Http\Controllers;

use App\Models\Recepcion;
use App\Models\Producto;
use App\Models\ProductoAsignacionActual;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecepcionController extends Controller
{
    public function index()
    {
        $recepciones = Recepcion::with(['responsable', 'area', 'productos'])
            ->orderBy('fecha_devolucion', 'desc')
            ->get();

        return response()->json($recepciones);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'responsable_id'   => 'required|exists:responsables,id',
            'area_id'          => 'required|exists:departamentos,id',
            'fecha_devolucion' => 'required|date',
            'categoria'        => 'nullable|string|max:255',
            'productos'        => 'required|array|min:1',
            'productos.*'      => 'exists:productos,id',
        ]);

        $productoIds = array_values(array_unique(array_map('intval', $data['productos'])));

        return DB::transaction(function () use ($data, $productoIds) {

            // Lock productos
            Producto::whereIn('id', $productoIds)->lockForUpdate()->get();

            // Regla: no recepcionar productos inactivos
            $inactivos = Producto::whereIn('id', $productoIds)->where('estado', '!=', 'Activo')->get(['id','codigo','nombre','estado']);
            if ($inactivos->isNotEmpty()) {
                $lista = $inactivos
                    ->map(fn ($p) => ($p->codigo ?? "ID {$p->id}") . " - " . ($p->nombre ?? '') . " ({$p->estado})")
                    ->implode(' | ');

                throw ValidationException::withMessages([
                    'productos' => ["No se puede recepcionar: hay bienes Inactivos. {$lista}"]
                ]);
            }

            // Validar que estén asignados actualmente al responsable
            $rows = ProductoAsignacionActual::whereIn('producto_id', $productoIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('producto_id');

            $invalidos = [];
            foreach ($productoIds as $pid) {
                if (!isset($rows[$pid])) {
                    $invalidos[] = "producto_id {$pid} (no está asignado actualmente)";
                    continue;
                }
                if ((int)$rows[$pid]->responsable_id !== (int)$data['responsable_id']) {
                    $invalidos[] = "producto_id {$pid} (asignado a responsable_id {$rows[$pid]->responsable_id})";
                    continue;
                }
            }

            if (!empty($invalidos)) {
                throw ValidationException::withMessages([
                    'productos' => ['No se puede recepcionar: ' . implode(' | ', $invalidos)]
                ]);
            }

            $recepcion = Recepcion::create([
                'responsable_id'   => $data['responsable_id'],
                'area_id'          => $data['area_id'],
                'fecha_devolucion' => $data['fecha_devolucion'],
                'categoria'        => $data['categoria'] ?? null,
                'acta_id'          => null,
            ]);

            $recepcion->productos()->sync($productoIds);

            // Quitar estado actual
            ProductoAsignacionActual::whereIn('producto_id', $productoIds)->delete();

            // Movimiento (usa tu helper global si existe)
            if (function_exists('registrarMovimiento')) {
                $recepcion->load(['responsable', 'area', 'productos']);
                $lista = $recepcion->productos
                    ->map(fn ($p) => trim(($p->codigo ?? '') . ' - ' . ($p->nombre ?? '')))
                    ->filter()
                    ->values()
                    ->implode(', ');

                $desc = "Recepción creada | Responsable: " .
                    trim(($recepcion->responsable->nombre ?? '') . ' ' . ($recepcion->responsable->apellido ?? '')) .
                    " | Área: " . ($recepcion->area->nombre ?? 'N/A') .
                    " | Fecha: " . ($recepcion->fecha_devolucion ?? '') .
                    " | Productos: " . ($lista ?: 'N/A');

                registrarMovimiento('Recepción creada', $desc);
            }

            return response()->json($recepcion->load(['responsable','area','productos']), 201);
        });
    }

    public function update(Request $request, $id)
    {
        $recepcion = Recepcion::with(['productos', 'responsable', 'area'])->findOrFail($id);

        $data = $request->validate([
            'responsable_id'   => 'required|exists:responsables,id',
            'area_id'          => 'required|exists:departamentos,id',
            'fecha_devolucion' => 'required|date',
            'categoria'        => 'nullable|string|max:255',
            'productos'        => 'required|array|min:1',
            'productos.*'      => 'exists:productos,id',
        ]);

        $oldIds = $recepcion->productos->pluck('id')->map(fn ($x) => (int)$x)->all();
        $newIds = array_values(array_unique(array_map('intval', $data['productos'])));

        sort($oldIds);
        sort($newIds);

        if ($oldIds !== $newIds) {
            throw ValidationException::withMessages([
                'productos' => ['No se permite cambiar los productos de una recepción ya registrada.']
            ]);
        }

        return DB::transaction(function () use ($recepcion, $data) {

            $antesFecha = $recepcion->fecha_devolucion;
            $antesArea  = $recepcion->area_id;
            $antesCat   = $recepcion->categoria;

            $recepcion->update([
                'responsable_id'   => $data['responsable_id'],
                'area_id'          => $data['area_id'],
                'fecha_devolucion' => $data['fecha_devolucion'],
                'categoria'        => $data['categoria'] ?? null,
            ]);

            if (function_exists('registrarMovimiento')) {
                $recepcion->load(['responsable', 'area', 'productos']);

                $lista = $recepcion->productos
                    ->map(fn ($p) => trim(($p->codigo ?? '') . ' - ' . ($p->nombre ?? '')))
                    ->filter()
                    ->values()
                    ->implode(', ');

                $cambios = [];
                if ($antesFecha !== $recepcion->fecha_devolucion) $cambios[] = "Fecha: {$antesFecha} → {$recepcion->fecha_devolucion}";
                if ((string)$antesArea !== (string)$recepcion->area_id) $cambios[] = "Área ID: {$antesArea} → {$recepcion->area_id}";
                if ((string)$antesCat !== (string)$recepcion->categoria) $cambios[] = "Categoría: {$antesCat} → {$recepcion->categoria}";

                $desc = "Recepción actualizada (ID {$recepcion->id}) | Responsable: " .
                    trim(($recepcion->responsable->nombre ?? '') . ' ' . ($recepcion->responsable->apellido ?? '')) .
                    " | Área: " . ($recepcion->area->nombre ?? 'N/A') .
                    " | Productos: " . ($lista ?: 'N/A') .
                    (count($cambios) ? " | Cambios: " . implode(' | ', $cambios) : "");

                registrarMovimiento('Recepción actualizada', $desc);
            }

            return response()->json($recepcion->load(['responsable','area','productos']));
        });
    }

    public function destroy($id)
    {
        $recepcion = Recepcion::with(['responsable', 'area', 'productos'])->findOrFail($id);

        return DB::transaction(function () use ($recepcion) {

            if (function_exists('registrarMovimiento')) {
                $lista = $recepcion->productos
                    ->map(fn ($p) => trim(($p->codigo ?? '') . ' - ' . ($p->nombre ?? '')))
                    ->filter()
                    ->values()
                    ->implode(', ');

                $desc = "Recepción eliminada (ID {$recepcion->id}) | Responsable: " .
                    trim(($recepcion->responsable->nombre ?? '') . ' ' . ($recepcion->responsable->apellido ?? '')) .
                    " | Área: " . ($recepcion->area->nombre ?? 'N/A') .
                    " | Fecha: " . ($recepcion->fecha_devolucion ?? '') .
                    " | Productos: " . ($lista ?: 'N/A');

                registrarMovimiento('Recepción eliminada', $desc);
            }

            $recepcion->delete();

            return response()->json(['message' => 'Recepción eliminada'], 200);
        });
    }
}
