<?php

namespace Database\Seeders\Concerns;

use RuntimeException;

trait RequiresSeederPassword
{
    /**
     * Resolve the default seeder password from SEEDER_DEFAULT_PASSWORD.
     * Throws a clear exception if missing or empty (no hardcoded fallback).
     */
    protected function resolveSeederPassword(): string
    {
        $password = trim((string) env('SEEDER_DEFAULT_PASSWORD', ''));

        if ($password === '') {
            throw new RuntimeException(
                'SEEDER_DEFAULT_PASSWORD is not set or empty. Please set SEEDER_DEFAULT_PASSWORD in your .env file before running user seeders.'
            );
        }

        return $password;
    }
}
