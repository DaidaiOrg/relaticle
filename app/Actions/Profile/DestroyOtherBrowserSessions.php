<?php

declare(strict_types=1);

namespace App\Actions\Profile;

use App\Actions\Auth\RevokeUserTokens;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class DestroyOtherBrowserSessions
{
    public function __construct(private RevokeUserTokens $revokeUserTokens) {}

    /**
     * Only the database driver can find a user's other live sessions, so the
     * result says whether they were ended. Identity is confirmed by the caller.
     */
    public function execute(User $user, string $currentSessionId): bool
    {
        $this->revokeUserTokens->execute($user);

        $user->setRememberToken(Str::random(60));
        $user->save();

        if (config('session.driver') !== 'database') {
            return false;
        }

        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->getAuthIdentifier())
            ->where('id', '!=', $currentSessionId)
            ->delete();

        return true;
    }
}
