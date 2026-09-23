<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ApiResponse;
use App\Http\Controllers\Controller;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResendOtpRequest;
use App\Http\Requests\VerifyEmailRequest;
use App\Mail\VerifyOtpMail;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Throwable;

class AuthController extends Controller
{
    private function generateUniqueUsername(string $name): string
    {

        $base = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $name));

        if (empty($base)) {
            $base = 'user';
        }

        do {

            $username = $base.'_'.rand(100000, 999999);

        } while (User::where('username', $username)->exists());

        return $username;
    }

    public function register(RegisterRequest $request)
    {
        try {

            if (User::where('email', $request->email)->exists()) {
                return ApiResponse::error('An account with this email already exists.', 409);
            }
            $autoUsername = $this->generateUniqueUsername($request->name);

            $user = DB::transaction(function () use ($request, $autoUsername) {
                return User::create([
                    'name' => $request->name,
                    'email' => $request->email,
                    'username' => $autoUsername,
                    'password' => $request->password, // Hashed automatically via model cast
                    'email_verified_at' => now(), // Auto-verify — no OTP step wired to this flow
                ]);
            });
            $user = $user->fresh();
            $token = JWTAuth::fromUser($user);

            return ApiResponse::success([
                'token' => $token,
                'user' => $user,
            ], 'Registration successful!', 201); // 201 Created

        } catch (Throwable $e) {
            return ApiResponse::error('Registration failed due to a server error', 500, $e->getMessage());
        }
    }

    public function registerWithVerify(RegisterRequest $request)
    {

        try {
            $autoUsername = $this->generateUniqueUsername($request->name);

            if (User::where('email', $request->email)->exists()) {
                return ApiResponse::error('An account with this email already exists.', 409);
            }

            $otpCode = (string) rand(100000, 999999);
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'username' => $autoUsername,
                'password' => $request->password,
                'email_verified_at' => null, // Unverified
                'email_otp' => $otpCode,
                'email_otp_expires_at' => now()->addMinutes(10),
            ]);

            try {
                if (! config('app.debug')) {
                    Mail::to($user->email)->send(new VerifyOtpMail($otpCode, $user->name));
                }

                return ApiResponse::success([
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'email_otp' => $otpCode,
                    'requires_verify' => true,
                ], 'Registration successful. Verification code sent to your email.', 200);
            } catch (Throwable $e) {
                return ApiResponse::warning([
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'requires_verify' => true,
                ], 'Registration successful. Verification code sent to your email.', 200);
            }

        } catch (Throwable $e) {

            return ApiResponse::error('Internal server error', 500, $e->getMessage());

        }

    }

    public function verifyEmail(VerifyEmailRequest $request)
    {
        try {
            $user = User::find($request->user_id);

            if (! $user->email_otp || $user->email_otp !== $request->otp) {
                return ApiResponse::error('Invalid verification code.', 400);
            }

            if (now()->greaterThan($user->email_otp_expires_at)) {
                return ApiResponse::error('Verification code has expired. Please request a new one.', 400);
            }

            // Mark user verified and wipe OTP
            $user->update([
                'email_verified_at' => now(),
                'email_otp' => null,
                'email_otp_expires_at' => null,
            ]);

            // Generate JWT Token
            $token = JWTAuth::fromUser($user);

            return ApiResponse::success([
                'token' => $token,
                'user' => $user,
            ], 'Email verified successfully!');

        } catch (Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(), // <-- This will pinpoint the exact file and line!
            ], 500);
            // return ApiResponse::error('Email verification failed due to a server error', 500, $e->getMessage());
        }
    }

    public function resendOtp(ResendOtpRequest $request)
    {
        try {
            $user = User::find($request->user_id);

            if (! is_null($user->email_verified_at)) {
                return ApiResponse::error('This account is already verified.', 400);
            }

            $otpCode = (string) rand(100000, 999999);

            $user->update([
                'email_otp' => $otpCode,
                'email_otp_expires_at' => now()->addMinutes(10),
            ]);

            try {
                if (! config('app.debug')) {
                    Mail::to($user->email)->send(new VerifyOtpMail($otpCode, $user->name));
                }

            } catch (Throwable $e) {
                return ApiResponse::error('Failed to resend verification email.', 500);
            }

            return ApiResponse::success(['otp' => $otpCode], 'A new verification code has been sent to your email.');

        } catch (Throwable $e) {
            return ApiResponse::error('Failed to resend OTP due to a server error', 500, $e->getMessage());
        }
    }

    public function login(LoginRequest $request)
    {
        try {
            $credentials = $request->only('email', 'password');

            if (! $token = JWTAuth::attempt($credentials)) {

                return ApiResponse::error('Invalid email or password credentials.', 401);
            }

            $user = auth()->user();

            if (is_null($user->email_verified_at)) {
                JWTAuth::setToken($token)->invalidate();

                return ApiResponse::error(
                    'Your email address is not verified yet.',
                    403,
                    null,
                    ['user_id' => $user->id, 'requires_verify' => true], // always sent
                );
            }

            // 5. Success response (200 OK) with token and full user model
            return ApiResponse::success([
                'token' => $token,
                'user' => $user,
            ], 'Login successful', 200);

        } catch (Throwable $e) {
            return ApiResponse::error('Login failed due to a server error', 500, $e->getMessage());
        }
    }

    /**
     * Get current authenticated user profile.
     * GET /api/auth/me
     */
    public function getUserAccount()
    {
        try {

            $user = auth()->user();

            if (! $user) {
                return ApiResponse::error('User not found or unauthenticated', 401);
            }

            return ApiResponse::success([
                'user' => $user,

            ], 'User account retrieved successfully');

        } catch (Throwable $e) {
            return ApiResponse::error('Failed to retrieve user account', 500, $e->getMessage());
        }
    }

    public function refreshToken()
    {
        try {
            // Generates a brand new access token for the authenticated user
            $newToken = auth('api')->refresh();

            return ApiResponse::success([
                'access_token' => $newToken,
                'token_type' => 'bearer',
                'expires_in' => auth('api')->factory()->getTTL() * 60, // in seconds
            ], 'Token refreshed successfully');

        } catch (Throwable $e) {
            return ApiResponse::error('Token cannot be refreshed. Please log in again.', 401);
        }
    }

    public function logout()
    {
        try {
            // Blacklists the current Bearer token
            auth('api')->logout();

            return ApiResponse::success(null, 'Successfully logged out');

        } catch (Throwable $e) {
            return ApiResponse::error('Failed to log out, token may already be invalid', 500);
        }
    }
}
