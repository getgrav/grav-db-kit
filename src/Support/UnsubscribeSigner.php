<?php

declare(strict_types=1);

namespace TrilbyMedia\GravDbKit\Support;

/**
 * Signed one-click unsubscribe links. The token is an HMAC-SHA256 over
 * "{subject}|{scope}" with an installation secret kept in the KV store, so a
 * link works without a login but cannot be forged or retargeted at another
 * person or another list.
 *
 * The subject is whoever the link unsubscribes (a user or person id, an
 * address); the scope is what from ('all', or one email group such as
 * 'replies'). Forum Pro signed an integer user id the same way, so its
 * existing links verify unchanged when the id is passed as a string.
 *
 * A scope may not contain '|': with the subject free-form, that is what keeps
 * ("a|b", "c") and ("a", "b|c") from signing alike.
 */
final class UnsubscribeSigner
{
    public const SECRET_KEY = 'unsubscribe_secret';

    public function __construct(
        private readonly KvStore $kv,
        private readonly string $secretKey = self::SECRET_KEY,
    ) {
    }

    public function sign(string $subject, string $scope): string
    {
        if (str_contains($scope, '|')) {
            throw new \InvalidArgumentException("An unsubscribe scope may not contain '|': {$scope}");
        }

        return hash_hmac('sha256', $subject . '|' . $scope, $this->secret());
    }

    public function verify(string $subject, string $scope, string $token): bool
    {
        if ($token === '' || str_contains($scope, '|')) {
            return false;
        }

        return hash_equals($this->sign($subject, $scope), $token);
    }

    private function secret(): string
    {
        return $this->kv->remember($this->secretKey, static fn (): string => bin2hex(random_bytes(32)));
    }
}
