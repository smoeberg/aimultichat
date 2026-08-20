<?php

declare(strict_types=1);

namespace Http\Middleware;

use Closure;
use Models\User;
use Core\Security;

/**
 * Middleware to check if user has a specific capability
 */
final class CheckCapability
{
    /**
     * Handle an incoming request
     *
     * @param array $request The request data
     * @param Closure $next The next middleware
     * @param string $capability The required capability
     * @return mixed
     */
    public function handle(array $request, Closure $next, string $capability)
    {
        $user = $this->getCurrentUser();
        
        if (!$user || !$user->hasCapability($capability)) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'error' => 'Du har ikke tilladelse til denne handling.',
                'capability_required' => $capability,
            ]);
            exit;
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
