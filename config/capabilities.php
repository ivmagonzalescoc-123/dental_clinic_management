<?php

require_once __DIR__ . '/security.php';
security_start_session();

if (!function_exists('capabilities_role_map')) {
    function capabilities_role_map(): array
    {
        return [
            'admin' => [
                'manage_users',
                'manage_services',
                'manage_reports',
                'manage_doctor_schedule',
                'appointment.create',
                'appointment.manage',
                'arrivals.manage',
                'billing.record',
                'patient.manage',
                'profile.update_own',
            ],
            'doctor' => [
                'clinical.save',
                'appointment.view_assigned',
                'appointment.manage',
                'arrivals.manage',
                'profile.update_own',
            ],
            'secretary' => [
                'appointment.create',
                'appointment.manage',
                'arrivals.manage',
                'billing.record',
                'patient.manage',
                'profile.update_own',
            ],
            'patient' => [
                'appointment.create',
                'appointment.view_own',
                'profile.update_own',
            ],
        ];
    }
}

if (!function_exists('capabilities_current_role')) {
    function capabilities_current_role(): string
    {
        $role = strtolower((string)($_SESSION['role'] ?? ''));
        if ($role !== '') {
            return $role;
        }

        $userType = strtolower((string)($_SESSION['user_type'] ?? ''));
        if ($userType !== '') {
            return $userType;
        }

        return 'guest';
    }
}

if (!function_exists('capabilities_can')) {
    function capabilities_can(string $capability, ?string $role = null): bool
    {
        $activeRole = $role !== null ? strtolower($role) : capabilities_current_role();
        $map = capabilities_role_map();
        $caps = $map[$activeRole] ?? [];
        return in_array($capability, $caps, true);
    }
}

if (!function_exists('capabilities_require_json')) {
    function capabilities_require_json(string $capability, int $statusCode = 403): void
    {
        if (capabilities_can($capability)) {
            return;
        }

        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Access denied for this action.',
            'required_capability' => $capability,
        ]);
        exit;
    }
}

if (!function_exists('capabilities_require_web')) {
    function capabilities_require_web(string $capability, string $redirectUrl): void
    {
        if (capabilities_can($capability)) {
            return;
        }

        $_SESSION['message'] = 'Access denied for this action.';
        $_SESSION['message_type'] = 'danger';
        header('Location: ' . $redirectUrl);
        exit;
    }
}
