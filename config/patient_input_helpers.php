<?php
/**
 * Validation for a patient's personal and contact details, shared by Add Patient
 * (assets/api/patients/create_walkin.php) and the patient settings form
 * (assets/api/patients/update_patient.php). The same rules run in the browser in
 * assets/js/patient-account.js; keep the two in step.
 */

if (!function_exists('patient_normalize_mobile')) {
    /**
     * A Philippine mobile number in any common spelling ("0917 123 4567", "+63 917 123 4567",
     * "9171234567") as 11 digits starting with 09, or null when it isn't one.
     */
    function patient_normalize_mobile(string $raw): ?string
    {
        if (!preg_match('/^\+?[\d\s().\-]+$/', $raw)) return null;
        $digits = preg_replace('/\D+/', '', $raw);
        if (strlen($digits) === 12 && substr($digits, 0, 3) === '639') $digits = '0' . substr($digits, 2);
        elseif (strlen($digits) === 10 && $digits[0] === '9') $digits = '0' . $digits;
        return preg_match('/^09\d{9}$/', $digits) ? $digits : null;
    }
}

if (!function_exists('patient_validate_details')) {
    /**
     * @param array $in   raw request fields
     * @param array $opts require_dob: the birthdate must be given.
     *                    strict: also check how names and the phone number are written. Staff
     *                    forms use it; converting a guest booking does not, because there the
     *                    secretary cannot correct what the guest typed.
     * @return array{values: array, errors: array<string,string>} errors are keyed by field name
     */
    function patient_validate_details(array $in, array $opts = []): array
    {
        $requireDob = !empty($opts['require_dob']);
        $strict = !array_key_exists('strict', $opts) || !empty($opts['strict']);
        $errors = [];
        $text = function (string $key, int $max) use ($in): string {
            $value = is_string($in[$key] ?? null) ? $in[$key] : '';
            return mb_substr(trim(preg_replace('/\s+/u', ' ', $value)), 0, $max);
        };

        $values = [
            'first_name' => $text('first_name', 100),
            'middle_name' => $text('middle_name', 100),
            'last_name' => $text('last_name', 100),
            'email' => $text('email', 150),
            'phone_number' => $text('phone_number', 30),
            'date_of_birth' => $text('date_of_birth', 20),
            'gender' => $text('gender', 20),
            'address' => $text('address', 500),
            'occupation' => $text('occupation', 150),
        ];

        $nameRule = '/^\p{L}[\p{L}\p{M} .\'’\-]*$/u';
        $nameLabels = ['first_name' => 'first name', 'middle_name' => 'middle name', 'last_name' => 'last name'];
        foreach ($nameLabels as $key => $label) {
            $name = $values[$key];
            if ($name === '') {
                if ($key !== 'middle_name') $errors[$key] = 'Enter the ' . $label . '.';
                continue;
            }
            if (!$strict) continue;
            if (!preg_match($nameRule, $name)) {
                $errors[$key] = 'The ' . $label . ' can only contain letters, spaces, hyphens, apostrophes and periods.';
            } elseif ($key !== 'middle_name' && mb_strlen($name) < 2) {
                $errors[$key] = 'The ' . $label . ' must be at least 2 letters.';
            }
        }

        if ($values['email'] === '') {
            $errors['email'] = 'Enter the email address. It is needed for the patient portal account.';
        } elseif (!filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Enter a valid email address, e.g. name@example.com.';
        }

        if ($values['phone_number'] !== '' && $strict) {
            $mobile = patient_normalize_mobile($values['phone_number']);
            if ($mobile === null) {
                $errors['phone_number'] = 'Enter an 11-digit mobile number starting with 09, e.g. 0917 123 4567.';
            } else {
                $values['phone_number'] = $mobile;
            }
        }

        $dob = $values['date_of_birth'];
        if ($dob === '') {
            $values['date_of_birth'] = null;
            if ($requireDob) $errors['date_of_birth'] = 'Enter the birthdate.';
        } else {
            $parsed = DateTime::createFromFormat('!Y-m-d', $dob);
            $problem = null;
            if (!$parsed || $parsed->format('Y-m-d') !== $dob) $problem = 'Enter a valid birthdate.';
            elseif ($dob > date('Y-m-d')) $problem = 'The birthdate cannot be in the future.';
            elseif ($dob < date('Y-m-d', strtotime('-120 years'))) $problem = 'Check the birth year. It is more than 120 years ago.';
            if ($problem !== null) {
                // An unusable date is never stored; outside a staff form it is dropped quietly.
                $values['date_of_birth'] = null;
                if ($strict || $requireDob) $errors['date_of_birth'] = $problem;
            }
        }

        if (!in_array($values['gender'], ['', 'Male', 'Female', 'Other'], true)) {
            if ($strict) $errors['gender'] = 'Select a gender from the list.';
            else $values['gender'] = '';
        }

        return ['values' => $values, 'errors' => $errors];
    }
}
