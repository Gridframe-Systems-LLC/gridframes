<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\SuiProofException;
use App\Http\Controllers\Controller;
use App\Models\SuiIdentity;
use App\Services\Sui\SuiIdentityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SuiIdentityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $team = $this->teamFromToken($request);

        return response()->json(
            $team->suiIdentities()
                ->whereNull('unbound_at')
                ->orderByDesc('verified_at')
                ->get()
                ->map(fn (SuiIdentity $identity) => $this->present($identity))
        );
    }

    public function challenge(Request $request): JsonResponse
    {
        $challenge = app(SuiIdentityService::class)->challenge($this->teamFromToken($request));

        return response()->json([
            'message' => $challenge['message'],
            'expires_at' => $challenge['expires_at']->toIso8601String(),
            'how' => 'Sign the message as a Sui personal message with the key that owns the address, then POST /api/v1/team/sui/verify with the address, the exact message, and the wallet signature.',
        ]);
    }

    public function verify_identity(Request $request): JsonResponse
    {
        $data = $request->validate([
            'address' => ['required', 'string'],
            'message' => ['required', 'string'],
            'signature' => ['required', 'string'],
        ]);

        try {
            $identity = app(SuiIdentityService::class)->verify(
                $this->teamFromToken($request),
                $data['address'],
                $data['message'],
                $data['signature'],
            );
        } catch (SuiProofException $e) {
            return response()->json(['error' => ['kind' => $e->kind, 'message' => $e->getMessage()]], 422);
        }

        return response()->json($this->present($identity), 201);
    }

    public function destroy(Request $request, string $sui_identity): JsonResponse
    {
        $team = $this->teamFromToken($request);
        $identity = SuiIdentity::where('team_id', $team->id)
            ->where('id', (int) $sui_identity)
            ->whereNull('unbound_at')
            ->first();
        if (! $identity) {
            return response()->json(['error' => ['message' => 'No such identity belongs to this team.']], 404);
        }
        // The row stays; the address never binds to another team. This team may bind it again.
        $identity->update(['unbound_at' => now()]);

        return response()->json(['success' => true]);
    }

    private function teamFromToken(Request $request)
    {
        $teamId = getTeamIdFromToken();
        $team = is_null($teamId) ? null : auth()->user()->teams->where('id', $teamId)->first();
        if (! $team) {
            abort(401, 'Invalid token.');
        }

        return $team;
    }

    private function present(SuiIdentity $identity): array
    {
        return [
            'id' => $identity->id,
            'sui_address' => $identity->sui_address,
            'sui_ns_name' => $identity->sui_ns_name,
            'public_key' => $identity->public_key,
            'verified_at' => $identity->verified_at->toIso8601String(),
        ];
    }
}
