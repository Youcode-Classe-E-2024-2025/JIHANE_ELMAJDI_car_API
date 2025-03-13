<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\RentalResource;
use App\Models\Car;
use App\Models\Rental;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RentalController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/rentals",
     *     tags={"Rentals"},
     *     summary="Get list of rentals",
     *     description="Returns list of rentals for admin or user's own rentals",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filter by rental status",
     *         required=false,
     *         @OA\Schema(
     *             type="string",
     *             enum={"pending", "active", "completed", "cancelled"}
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="page",
     *         in="query",
     *         description="Page number",
     *         required=false,
     *         @OA\Schema(
     *             type="integer",
     *             format="int32"
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Successful operation",
     *     ),
     * )
     */
    public function index(Request $request)
    {
        $user = $request->user();
        $status = $request->query('status');

        $query = Rental::query();

        // If not admin, only show user's rentals
        if (!$user->isAdmin()) {
            $query->where('user_id', $user->id);
        }

        if ($status) {
            $query->where('status', $status);
        }

        $rentals = $query->with(['car', 'user'])->paginate(10);

        return RentalResource::collection($rentals);
    }

    /**
     * @OA\Post(
     *     path="/api/rentals",
     *     tags={"Rentals"},
     *     summary="Create a new rental",
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"car_id", "start_date", "end_date"},
     *             @OA\Property(property="car_id", type="integer", example=1),
     *             @OA\Property(property="start_date", type="string", format="date", example="2023-03-15"),
     *             @OA\Property(property="end_date", type="string", format="date", example="2023-03-20"),
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Rental created successfully",
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *     ),
     *     @OA\Response(
     *         response=409,
     *         description="Car not available for rental",
     *     )
     * )
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'car_id' => 'required|exists:cars,id',
            'start_date' => 'required|date|after_or_equal:today',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        $user = $request->user();
        $car = Car::findOrFail($validated['car_id']);

        // Check if car is available
        if ($car->status !== 'available') {
            return response()->json([
                'message' => 'Car is not available for rental'
            ], 409);
        }

        // Check if car is already booked for the requested dates
        $conflictingRentals = Rental::where('car_id', $car->id)
            ->where('status', '!=', 'cancelled')
            ->where(function ($query) use ($validated) {
                $query->whereBetween('start_date', [$validated['start_date'], $validated['end_date']])
                    ->orWhereBetween('end_date', [$validated['start_date'], $validated['end_date']])
                    ->orWhere(function ($q) use ($validated) {
                        $q->where('start_date', '<=', $validated['start_date'])
                          ->where('end_date', '>=', $validated['end_date']);
                    });
            })
            ->exists();

        if ($conflictingRentals) {
            return response()->json([
                'message' => 'Car is already booked for the selected dates'
            ], 409);
        }

        // Create rental in a transaction
        DB::beginTransaction();
        try {
            $rental = new Rental();
            $rental->fill($validated);
            $rental->user_id = $user->id;
            $rental->status = 'pending';

            // Calculate total price
            $rental->car()->associate($car);
            $rental->calculateTotalPrice();

            $rental->save();

            // Update car status
            $car->status = 'rented';
            $car->save();

            DB::commit();

            return new RentalResource($rental);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to create rental: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/rentals/{id}",
     *     tags={"Rentals"},
     *     summary="Get a specific rental",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the rental",
     *         @OA\Schema(
     *             type="integer",
     *             format="int64"
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Rental details",
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Rental not found",
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized access",
     *     )
     * )
     */
    public function show(Request $request, Rental $rental)
    {
        $user = $request->user();

        // Check if user is authorized to view this rental
        if (!$user->isAdmin() && $rental->user_id !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized access'
            ], 403);
        }

        return new RentalResource($rental->load(['car', 'user', 'payments']));
    }

    /**
     * @OA\Put(
     *     path="/api/rentals/{id}",
     *     tags={"Rentals"},
     *     summary="Update a rental",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the rental",
     *         @OA\Schema(
     *             type="integer",
     *             format="int64"
     *         )
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="status", type="string", enum={"pending", "active", "completed", "cancelled"}, example="active"),
     *             @OA\Property(property="start_date", type="string", format="date", example="2023-03-15"),
     *             @OA\Property(property="end_date", type="string", format="date", example="2023-03-20"),
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Rental updated successfully",
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Rental not found",
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized action",
     *     )
     * )
     */
    public function update(Request $request, Rental $rental)
    {
        $user = $request->user();

        // Check if user is authorized to update this rental
        if (!$user->isAdmin() && $rental->user_id !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized access'
            ], 403);
        }

        $validated = $request->validate([
            'status' => 'sometimes|in:pending,active,completed,cancelled',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after_or_equal:start_date',
        ]);

        // If dates are being updated, need to check for conflicts
        if (isset($validated['start_date']) || isset($validated['end_date'])) {
            $start = $validated['start_date'] ?? $rental->start_date;
            $end = $validated['end_date'] ?? $rental->end_date;

            $conflictingRentals = Rental::where('car_id', $rental->car_id)
                ->where('id', '!=', $rental->id)
                ->where('status', '!=', 'cancelled')
                ->where(function ($query) use ($start, $end) {
                    $query->whereBetween('start_date', [$start, $end])
                        ->orWhereBetween('end_date', [$start, $end])
                        ->orWhere(function ($q) use ($start, $end) {
                            $q->where('start_date', '<=', $start)
                              ->where('end_date', '>=', $end);
                        });
                })
                ->exists();

            if ($conflictingRentals) {
                return response()->json([
                    'message' => 'Car is already booked for the selected dates'
                ], 409);
            }
        }

        // Handle status changes
        $oldStatus = $rental->status;
        $newStatus = $validated['status'] ?? $oldStatus;

        DB::beginTransaction();
        try {
            $rental->update($validated);

            // If dates changed, recalculate total price
            if (isset($validated['start_date']) || isset($validated['end_date'])) {
                $rental->calculateTotalPrice();
                $rental->save();
            }

            // Handle car status based on rental status change
            if ($oldStatus !== $newStatus) {
                $car = $rental->car;

                if ($newStatus === 'cancelled') {
                    $car->status = 'available';
                } elseif ($newStatus === 'completed') {
                    $car->status = 'available';
                } elseif ($newStatus === 'active') {
                    $car->status = 'rented';
                }

                $car->save();
            }

            DB::commit();

            return new RentalResource($rental);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to update rental: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Delete(
     *     path="/api/rentals/{id}",
     *     tags={"Rentals"},
     *     summary="Cancel a rental",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the rental",
     *         @OA\Schema(
     *             type="integer",
     *             format="int64"
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Rental cancelled successfully",
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Rental not found",
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized action",
     *     )
     * )
     */
    public function destroy(Request $request, Rental $rental)
    {
        $user = $request->user();

        // Check if user is authorized to cancel this rental
        if (!$user->isAdmin() && $rental->user_id !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized access'
            ], 403);
        }

        // Only pending or active rentals can be cancelled
        if (!in_array($rental->status, ['pending', 'active'])) {
            return response()->json([
                'message' => 'Only pending or active rentals can be cancelled'
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Update rental status to cancelled
            $rental->status = 'cancelled';
            $rental->save();

            // Update car status to available
            $car = $rental->car;
            $car->status = 'available';
            $car->save();

            DB::commit();

            return response()->json([
                'message' => 'Rental cancelled successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to cancel rental: ' . $e->getMessage()
            ], 500);
        }
    }
}
