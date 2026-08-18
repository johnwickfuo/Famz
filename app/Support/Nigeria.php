<?php

namespace App\Support;

/**
 * Reference data for the places this platform serves. Kept here rather than in
 * a table because it changes about once a generation.
 */
class Nigeria
{
    /**
     * @return array<int, string>
     */
    public static function states(): array
    {
        return [
            'Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa', 'Benue', 'Borno',
            'Cross River', 'Delta', 'Ebonyi', 'Edo', 'Ekiti', 'Enugu', 'FCT - Abuja', 'Gombe',
            'Imo', 'Jigawa', 'Kaduna', 'Kano', 'Katsina', 'Kebbi', 'Kogi', 'Kwara', 'Lagos',
            'Nasarawa', 'Niger', 'Ogun', 'Ondo', 'Osun', 'Oyo', 'Plateau', 'Rivers', 'Sokoto',
            'Taraba', 'Yobe', 'Zamfara',
        ];
    }
}
