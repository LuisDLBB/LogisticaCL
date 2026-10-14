<?php

namespace App\Fleet;

use Illuminate\Support\Facades\Process;
use Throwable;

class VehicleCertificateReader
{
    public function read(string $path): array
    {
        $python = config('fleet.certificate_python');
        if (! $python && app()->environment('local') && PHP_OS_FAMILY === 'Windows') {
            $python = getenv('USERPROFILE').'/.cache/codex-runtimes/codex-primary-runtime/dependencies/python/python.exe';
        }
        if (! $python) {
            return ['pages' => [], 'method' => 'pending'];
        }
        try {
            $result = Process::timeout(45)->run([$python, app_path('Fleet/read_certificate.py'), $path]);
            $data = $result->successful() ? json_decode($result->output(), true) : null;

            return is_array($data) && isset($data['pages']) && is_array($data['pages']) ? $data : ['pages' => [], 'method' => 'pending'];
        } catch (Throwable) {
            return ['pages' => [], 'method' => 'pending'];
        }
    }
}
