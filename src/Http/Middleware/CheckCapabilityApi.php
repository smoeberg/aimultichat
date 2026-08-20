<?php

declare(strict_types=1);

namespace Http\Middleware;

use Closure;
use Models\User;

/**
 * Middleware to check capability for API routes (returns JSON)
 */
final class CheckCapabilityApi
{
    /**
     * Handle an incoming API request
     *
     * @param array $request The request data
     * @param Closure $next The next middleware
     * @param string $capability The required capability
     * @return array JSON response
     */
    public function handle(array $request, Closure $next, string $capability)
    {
        $user = $this->getCurrentUser();
        
        if (!$user || !$user->hasCapability($capability)) {
            return [
                'success' => false,
                'error' => 'Forbidden',
                'message' => 'Du har ikke tilladelse til denne handling.',
                'capability_required' => $capability,
            ];
        }

        return $next($request);
    }

    /**
     * Get current user from session
     */
    private function getCurrentUser(): ?User
    {
        if (isset($_SESSION['uid'])) {
            return User::findById((int)$_SESSION['uid']);
        }
        return null;
    }
}
