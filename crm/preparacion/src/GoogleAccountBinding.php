<?php
declare(strict_types=1);
namespace TSF\Security;

/**
 * Evaluates an identity already verified by the Google OAuth server flow.
 * This class does not verify tokens, protect OAuth state, create users or sessions.
 */
final class GoogleAccountBinding
{
    /** @return 'deny'|'bind'|'allow' */
    public static function decide(
        bool $active,
        string $approvedEmail,
        ?string $boundSubject,
        string $verifiedEmail,
        bool $emailVerified,
        string $verifiedSubject
    ): string {
        if (!$active || !$emailVerified || $verifiedSubject === '' || trim($approvedEmail) === ''
            || strcasecmp(trim($approvedEmail), trim($verifiedEmail)) !== 0) {
            return 'deny';
        }
        if ($boundSubject === null) {
            return 'bind';
        }
        if ($boundSubject === '') {
            return 'deny';
        }
        return hash_equals($boundSubject, $verifiedSubject) ? 'allow' : 'deny';
    }
}
