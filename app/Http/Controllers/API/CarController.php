<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\CarResource;
use App\Models\Car;
use Illuminate\Http\Request;

class CarController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/cars",
     *     tags={"Cars"},
     *     summary="Get list of cars",
     *     description="Returns list of cars with pagination and filters",
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filter by car status",
     *         required=false,
     *         @OA\Schema(
     *             type="string",
     *             enum={"available", "rented", "maintenance"}
     *         )
     *     ),
     *     @OA\Parameter(
     *         name="brand",
     *         in="query",
     *         description="Filter by car brand",
     *         required=false,
     *         @OA\Schema(
     *             type="string"
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
        $status = $request->query('status');
        $brand = $request->query('brand');

        $query = Car::query();

        if ($status) {
            $query->where('status', $status);
        }

        if ($brand) {
            $query->where('brand', 'like', "%{$brand}%");
        }

        $cars = $query->paginate(10);

        return CarResource::collection($cars);
    }

    /**
     * @OA\Post(
     *     path="/api/cars",
     *     tags={"Cars"},
     *     summary="Create a new car",
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"brand", "model", "year", "color", "license_plate", "daily_rate"},
     *             @OA\Property(property="brand", type="string", example="Toyota"),
     *             @OA\Property(property="model", type="string", example="Corolla"),
     *             @OA\Property(property="year", type="integer", example=2020),
     *             @OA\Property(property="color", type="string", example="Blue"),
     *             @OA\Property(property="license_plate", type="string", example="ABC123"),
     *             @OA\Property(property="daily_rate", type="number", format="float", example=50.00),
     *             @OA\Property(property="status", type="string", enum={"available", "rented", "maintenance"}, example="available"),
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Car created successfully",
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized action",
     *     )
     * )
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'brand' => 'required|string|max:255',
            'model' => 'required|string|max:255',
            'year' => 'required|integer|min:1900|max:' . (date('Y') + 1),
            'color' => 'required|string|max:255',
            'license_plate' => 'required|string|max:20|unique:cars',
            'daily_rate' => 'required|numeric|min:0',
            'status' => 'sometimes|in:available,rented,maintenance',
        ]);

        $car = Car::create($validated);

        return new CarResource($car);
    }

    /**
     * @OA\Get(
     *     path="/api/cars/{id}",
     *     tags={"Cars"},
     *     summary="Get a specific car",
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the car",
     *         @OA\Schema(
     *             type="integer",
     *             format="int64"
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Car details",
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Car not found",
     *     )
     * )
     */
    public function show(Car $car)
    {
        return new CarResource($car);
    }

    /**
     * @OA\Put(
     *     path="/api/cars/{id}",
     *     tags={"Cars"},
     *     summary="Update a car",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the car",
     *         @OA\Schema(
     *             type="integer",
     *             format="int64"
     *         )
     *     ),
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             @OA\Property(property="brand", type="string", example="Toyota"),
     *             @OA\Property(property="model", type="string", example="Corolla"),
     *             @OA\Property(property="year", type="integer", example=2020),
     *             @OA\Property(property="color", type="string", example="Blue"),
     *             @OA\Property(property="license_plate", type="string", example="ABC123"),
     *             @OA\Property(property="daily_rate", type="number", format="float", example=50.00),
     *             @OA\Property(property="status", type="string", enum={"available", "rented", "maintenance"}, example="available"),
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Car updated successfully",
     *     ),
     *     @OA\Response(
     *         response=422,
     *         description="Validation error",
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Car not found",
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized action",
     *     )
     * )
     */
    public function update(Request $request, Car $car)
    {
        $validated = $request->validate([
            'brand' => 'sometimes|string|max:255',
            'model' => 'sometimes|string|max:255',
            'year' => 'sometimes|integer|min:1900|max:' . (date('Y') + 1),
            'color' => 'sometimes|string|max:255',
            'license_plate' => 'sometimes|string|max:20|unique:cars,license_plate,' . $car->id,
            'daily_rate' => 'sometimes|numeric|min:0',
            'status' => 'sometimes|in:available,rented,maintenance',
        ]);

        $car->update($validated);

        return new CarResource($car);
    }

    /**
     * @OA\Delete(
     *     path="/api/cars/{id}",
     *     tags={"Cars"},
     *     summary="Delete a car",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the car",
     *         @OA\Schema(
     *             type="integer",
     *             format="int64"
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Car deleted successfully",
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Car not found",
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized action",
     *     )
     * )
     */
    public function destroy(Car $car)
    {
        // Check if car has any active rentals before deleting
        if ($car->rentals()->where('status', 'active')->exists()) {
            return response()->json([
                'message' => 'Cannot delete car with active rentals'
            ], 409);
        }

        $car->delete();

        return response()->json([
            'message' => 'Car deleted successfully'
        ]);
    }
}
