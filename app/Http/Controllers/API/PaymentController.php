<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\PaymentResource;
use App\Models\Payment;
use App\Models\Rental;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/payments",
     *     tags={"Payments"},
     *     summary="Get list of payments",
     *     description="Returns list of payments for admin or user's own payments",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="status",
     *         in="query",
     *         description="Filter by payment status",
     *         required=false,
     *         @OA\Schema(
     *             type="string",
     *             enum={"pending", "completed", "failed", "refunded"}
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

        $query = Payment::query();

        // If not admin, only show user's payments
        if (!$user->isAdmin()) {
            $query->where('user_id', $user->id);
        }

        if ($status) {
            $query->where('status', $status);
        }

        $payments = $query->with(['rental', 'user'])->paginate(10);

        return PaymentResource::collection($payments);
    }

    /**
     * @OA\Post(
     *     path="/api/payments",
     *     tags={"Payments"},
     *     summary="Create a new payment",
     *     security={{"sanctum":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"rental_id", "amount", "payment_method"},
     *             @OA\Property(property="rental_id", type="integer", example=1),
     *             @OA\Property(property="amount", type="number", format="float", example=250.00),
     *             @OA\Property(property="payment_method", type="string", enum={"credit_card", "debit_card", "paypal"}, example="credit_card"),
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Payment created successfully",
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
            'rental_id' => 'required|exists:rentals,id',
            'amount' => 'required|numeric|min:0',
            'payment_method' => 'required|in:credit_card,debit_card,paypal',
        ]);

        $user = $request->user();
        $rental = Rental::findOrFail($validated['rental_id']);

        // Check if user is authorized to make payment for this rental
        if (!$user->isAdmin() && $rental->user_id !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized access'
            ], 403);
        }

        // Check if rental is in a valid status for payment
        if (!in_array($rental->status, ['pending', 'active'])) {
            return response()->json([
                'message' => 'Cannot make payment for a rental that is not pending or active'
            ], 422);
        }

        DB::beginTransaction();
        try {
            $payment = new Payment([
                'user_id' => $user->id,
                'rental_id' => $rental->id,
                'amount' => $validated['amount'],
                'payment_date' => now(),
                'payment_method' => $validated['payment_method'],
                'transaction_id' => Str::uuid()->toString(),
                'status' => 'completed',  // In a real app, this would be 'pending' until confirmed
            ]);

            $payment->save();

            // Update rental status if payment matches total price
            $totalPaid = $rental->payments()->where('status', 'completed')->sum('amount');
            if ($totalPaid >= $rental->total_price && $rental->status === 'pending') {
                $rental->status = 'active';
                $rental->save();
            }

            DB::commit();

            return new PaymentResource($payment);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to process payment: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * @OA\Get(
     *     path="/api/payments/{id}",
     *     tags={"Payments"},
     *     summary="Get a specific payment",
     *     security={{"sanctum":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         description="ID of the payment",
     *         @OA\Schema(
     *             type="integer",
     *             format="int64"
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="Payment details",
     *     ),
     *     @OA\Response(
     *         response=404,
     *         description="Payment not found",
     *     ),
     *     @OA\Response(
     *         response=403,
     *         description="Unauthorized access",
     *     )
     * )
     */
    public function show(Request $request, Payment $payment)
    {
        $user = $request->user();

        // Check if user is authorized to view this payment
        if (!$user->isAdmin() && $payment->user_id !== $user->id) {
            return response()->json([
                'message' => 'Unauthorized access'
            ], 403);
        }

        return new PaymentResource($payment->load(['rental', 'user']));
    }
}
