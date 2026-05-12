<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\FinancialClassifierService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly FinancialClassifierService $classifier)
    {
    }

    public function register(RegisterRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $defaultAssessmentPayload = [
            'monthly_income'       => 6000000,
            'monthly_expense'      => 4200000,
            'monthly_expense_total'=> 4200000,
            'actual_savings'       => 1800000,
            'budget_goal'          => 1200000,
            'emergency_fund'       => 5000000,
            'loan_payment'         => 0,
            'available_hours_per_week' => 8,
            'skills'               => ['communication'],
        ];

        [$user, $token] = DB::transaction(function () use ($validated, $defaultAssessmentPayload) {
            $user = User::create($validated);

            $classificationResult = $this->classifier->classify($defaultAssessmentPayload);

            $user->assessments()->create([
                ...$defaultAssessmentPayload,
                'classification' => $classificationResult['classification'],
                'ml_score'       => $classificationResult['score'] ?? null,
                'ml_explanation' => $classificationResult['explanation'] ?? null,
                'metadata'       => [
                    'source'                => $classificationResult['source'],
                    'classification_result' => $classificationResult,
                    'stage'                 => 'onboarding',
                ],
            ]);

            $token = $user->createToken('finary-token')->plainTextToken;

            return [$user, $token];
        });

        return response()->json([
            'message' => 'Register berhasil.',
            'token'   => $token,
            'user'    => new UserResource($user),
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Email atau password tidak valid.'],
            ]);
        }

        $token = $user->createToken('finary-token')->plainTextToken;

        return response()->json([
            'message' => 'Login berhasil.',
            'token'   => $token,
            'user'    => new UserResource($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json([
            'message' => 'Logout berhasil.',
        ]);
    }
}
