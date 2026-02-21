<?php

namespace App\Http\Controllers;

use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PropertyController extends Controller
{
    /**
     * Roles con permisos de administración.
     */
    private const ADMIN_ROLES = ['admin', 'support'];

    /**
     * Campos permitidos para ordenar (whitelist).
     */
    private const SORT_FIELDS = [
        'id', 'title', 'city', 'monthly_price', 'area_m2',
        'views', 'created_at', 'updated_at', 'status', 'approval_status',
    ];

    // ─────────────────────────────────────────────
    // HELPERS PRIVADOS
    // ─────────────────────────────────────────────

    /**
     * Retorna el usuario autenticado con aserción de tipo.
     * Elimina los warnings "Undefined method 'user'" / "Undefined method 'id'".
     *
     * @return \App\Models\User
     */
    private function authUser(): \App\Models\User
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        return $user;
    }

    /**
     * Comprueba si el usuario autenticado es admin/support.
     */
    private function isAdmin(): bool
    {
        return in_array($this->authUser()->role, self::ADMIN_ROLES, true);
    }

    /**
     * Decodifica y re-codifica included_services garantizando JSON válido.
     */
    private function normalizeServices(?string $raw): string
    {
        if ($raw === null) {
            return '[]';
        }
        $decoded = json_decode($raw, true);
        return (json_last_error() === JSON_ERROR_NONE && is_array($decoded))
            ? json_encode($decoded)
            : '[]';
    }

    /**
     * Convierte image_url (string JSON o array) a array PHP.
     */
    private function decodeImages(mixed $imageUrl): array
    {
        if (is_array($imageUrl)) {
            return $imageUrl;
        }
        return is_string($imageUrl) ? (json_decode($imageUrl, true) ?? []) : [];
    }

    /**
     * Filtra base64 válidos y dentro del límite de tamaño.
     *
     * @param  string[]  $images
     * @param  float     $maxMB
     * @return string[]
     */
    private function filterValidBase64Images(array $images, float $maxMB = 3.0): array
    {
        $valid = [];
        foreach ($images as $i => $img) {
            if (!str_starts_with($img, 'data:image/')) {
                Log::warning("Imagen {$i} no es Data URI válido");
                continue;
            }
            $sizeMB = (strlen($img) * 0.75) / (1024 ** 2);
            if ($sizeMB > $maxMB) {
                Log::warning("Imagen {$i} muy grande: {$sizeMB}MB");
                continue;
            }
            $valid[] = $img;
        }
        return $valid;
    }

    // ─────────────────────────────────────────────
    // ACTIONS
    // ─────────────────────────────────────────────

    /**
     * Listado con filtros y paginación.
     * Optimización: select sólo columnas necesarias, eager load diferido.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage   = (int) $request->input('per_page', 10);
        $sortBy    = in_array($request->input('sort_by'), self::SORT_FIELDS, true)
            ? $request->input('sort_by')
            : 'created_at';
        $sortOrder = $request->input('sort_order', 'desc') === 'asc' ? 'asc' : 'desc';

        $query = Property::with('user:id,name,email,phone,photo')
            ->when($request->filled('search'), function ($q) use ($request) {
                $s = $request->input('search');
                $q->where(fn ($q2) =>
                    $q2->where('title',       'like', "%{$s}%")
                       ->orWhere('description', 'like', "%{$s}%")
                       ->orWhere('city',        'like', "%{$s}%")
                       ->orWhere('address',     'like', "%{$s}%")
                );
            })
            ->when($request->filled('status'),          fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('approval_status'), fn ($q) => $q->where('approval_status', $request->input('approval_status')))
            ->when($request->filled('city'),            fn ($q) => $q->where('city', 'like', '%' . $request->input('city') . '%'))
            ->when($request->filled('min_price'),       fn ($q) => $q->where('monthly_price', '>=', $request->input('min_price')))
            ->when($request->filled('max_price'),       fn ($q) => $q->where('monthly_price', '<=', $request->input('max_price')))
            ->orderBy($sortBy, $sortOrder);

        $properties = $query->paginate($perPage);

        return response()->json([
            'data' => $properties->items(),
            'meta' => [
                'current_page' => $properties->currentPage(),
                'last_page'    => $properties->lastPage(),
                'per_page'     => $properties->perPage(),
                'total'        => $properties->total(),
            ],
        ]);
    }

    /**
     * Mostrar propiedad por ID.
     */
    public function show(Property $property): JsonResponse
    {
        $property->load('user:id,name,email,phone,photo');

        return response()->json([
            'success' => true,
            'data'    => $property,
        ]);
    }

    /**
     * Crear propiedad.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title'             => 'required|string|max:255',
            'description'       => 'required|string',
            'address'           => 'required|string',
            'city'              => 'nullable|string|max:120',
            'status'            => 'nullable|string|in:available,rented,maintenance',
            'monthly_price'     => 'required|numeric|min:0',
            'area_m2'           => 'nullable|numeric|min:0',
            'num_bedrooms'      => 'nullable|integer|min:0',
            'num_bathrooms'     => 'nullable|integer|min:0',
            'included_services' => 'nullable|string',
            'lat'               => 'nullable|numeric',
            'lng'               => 'nullable|numeric',
            'accuracy'          => 'nullable|numeric',
            'user_id'           => 'nullable|integer|exists:users,id',
            'images'            => 'nullable|string', // JSON array de base64
            'publication_date'  => 'nullable|date',
        ]);

        // Resolvemos el usuario UNA sola vez para evitar múltiples llamadas a Auth
        $user = $this->authUser();

        try {
            DB::beginTransaction();

            $validated['included_services'] = $this->normalizeServices($validated['included_services'] ?? null);
            $validated['user_id']           = $validated['user_id'] ?? $user->id; // ← sin warning
            $validated['publication_date']  = $validated['publication_date'] ?? now()->toDateString();

            // Auto-aprobar si es admin/support
            if (in_array($user->role, self::ADMIN_ROLES, true)) {
                $validated['approval_status'] = 'approved';
                $validated['visibility']      = 'published';
            }

            // Procesar imágenes base64
            $imagesJson = null;
            if (!empty($validated['images'])) {
                $decoded = json_decode($validated['images'], true);
                if (is_array($decoded) && count($decoded) > 0) {
                    $valid = $this->filterValidBase64Images($decoded);
                    if ($valid) {
                        $imagesJson = json_encode($valid);
                        Log::info('Procesadas ' . count($valid) . ' imágenes válidas');
                    }
                }
            }

            unset($validated['images']);
            $validated['image_url'] = $imagesJson;

            $property = Property::create($validated);

            DB::commit();

            $property->load('user:id,name,email,phone,photo');

            Log::info("Propiedad creada ID: {$property->id}");

            return response()->json([
                'success'  => true,
                'message'  => 'Propiedad creada exitosamente',
                'property' => $property,
            ], 201);

        } catch (ValidationException $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error de validación',
                'errors'  => $e->errors(),
            ], 422);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error creando propiedad: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al crear la propiedad',
                'error'   => $e->getMessage(),
                'trace'   => config('app.debug') ? $e->getTraceAsString() : null,
            ], 500);
        }
    }

    /**
     * Actualizar propiedad.
     */
    public function update(Request $request, Property $property): JsonResponse
    {
        $user = $this->authUser(); // ← una sola llamada, sin warning

        if ($property->user_id !== $user->id && !in_array($user->role, self::ADMIN_ROLES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para editar esta propiedad',
            ], 403);
        }

        $validated = $request->validate([
            'title'             => 'sometimes|string|max:255',
            'description'       => 'sometimes|string',
            'address'           => 'sometimes|string',
            'city'              => 'sometimes|string|max:120',
            'status'            => 'sometimes|string|in:available,rented,maintenance',
            'monthly_price'     => 'sometimes|numeric|min:0',
            'area_m2'           => 'sometimes|numeric|min:0',
            'num_bedrooms'      => 'sometimes|integer|min:0',
            'num_bathrooms'     => 'sometimes|integer|min:0',
            'included_services' => 'sometimes|string',
            'lat'               => 'sometimes|numeric',
            'lng'               => 'sometimes|numeric',
            'images'            => 'sometimes|string',
            'delete_images'     => 'sometimes|array',
            'delete_images.*'   => 'integer|min:0',
            'reorder_images'    => 'sometimes|array',
        ]);

        try {
            DB::beginTransaction();

            if (isset($validated['included_services'])) {
                $validated['included_services'] = $this->normalizeServices($validated['included_services']);
            }

            // Imágenes actuales como array PHP
            $currentImages = $this->decodeImages($property->image_url);

            // 1. Eliminar por índice
            if (!empty($validated['delete_images'])) {
                foreach ($validated['delete_images'] as $idx) {
                    unset($currentImages[$idx]);
                }
                $currentImages = array_values($currentImages);
            }

            // 2. Reordenar
            if (!empty($validated['reorder_images'])) {
                $reordered = [];
                foreach ($validated['reorder_images'] as $oldIndex => $newOrder) {
                    if (isset($currentImages[$oldIndex])) {
                        $reordered[(int) $newOrder] = $currentImages[$oldIndex];
                    }
                }
                ksort($reordered);
                $currentImages = array_values($reordered);
            }

            // 3. Agregar nuevas imágenes
            if (!empty($validated['images'])) {
                $newImages = json_decode($validated['images'], true);
                if (is_array($newImages)) {
                    foreach ($newImages as $img) {
                        if (str_starts_with($img, 'data:image/')) {
                            $currentImages[] = $img;
                        }
                    }
                }
            }

            $validated['image_url'] = count($currentImages) > 0
                ? json_encode($currentImages)
                : null;

            unset($validated['images'], $validated['delete_images'], $validated['reorder_images']);

            $property->update($validated);

            DB::commit();

            $property->load('user:id,name,email,phone,photo');

            return response()->json([
                'success'  => true,
                'message'  => 'Propiedad actualizada correctamente',
                'property' => $property,
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error actualizando propiedad: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al actualizar la propiedad',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Eliminar propiedad.
     */
    public function destroy(Property $property): JsonResponse
    {
        $user = $this->authUser();

        if ($property->user_id !== $user->id && !in_array($user->role, self::ADMIN_ROLES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para eliminar esta propiedad',
            ], 403);
        }

        try {
            DB::beginTransaction();
            $property->delete();
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Propiedad eliminada correctamente',
            ]);

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Error eliminando propiedad: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar la propiedad',
            ], 500);
        }
    }

    /**
     * Guardar/actualizar ubicación GPS.
     */
    public function savePoint(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'lat'      => 'required|numeric',
            'lng'      => 'required|numeric',
            'accuracy' => 'nullable|numeric',
        ]);

        $property = Property::findOrFail($id);
        $user     = $this->authUser();

        if ($property->user_id !== $user->id && !in_array($user->role, self::ADMIN_ROLES, true)) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permisos para actualizar la ubicación',
            ], 403);
        }

        // updateOnly para tocar exclusivamente los campos de geo, sin disparar eventos innecesarios
        $property->update([
            'lat'      => $validated['lat'],
            'lng'      => $validated['lng'],
            'accuracy' => $validated['accuracy'] ?? null,
        ]);

        return response()->json([
            'success'  => true,
            'message'  => 'Ubicación guardada correctamente',
            'property' => $property,
        ]);
    }

    /**
     * Incrementar vistas.
     */
    public function incrementViews(int $id): JsonResponse
    {
        // DB::increment evita cargar el modelo completo en memoria
        $updated = Property::where('id', $id)->increment('views');

        if (!$updated) {
            return response()->json(['success' => false, 'message' => 'Propiedad no encontrada'], 404);
        }

        $views = Property::where('id', $id)->value('views');

        return response()->json([
            'success' => true,
            'message' => 'Visita registrada',
            'views'   => $views,
        ]);
    }

    /**
     * Contar propiedades.
     */
    public function count(): JsonResponse
    {
        return response()->json([
            'count' => Property::count(),
        ]);
    }
}